/**
 * AiBridgeStream — lightweight client for Laravel AI Bridge.
 *
 * Handles both SSE and Reverb modes for receiving AI stream events.
 *
 * Usage (SSE):
 *   const stream = new AiBridgeStream({ mode: 'sse', url: '/ai-bridge/stream/sse' });
 *   stream.on('text', (content) => { ... });
 *   stream.on('done', (usage) => { ... });
 *   stream.send({ message: 'Hello', conversation_id: 'conv-1' });
 *
 * Usage (Reverb):
 *   // 1. POST to broadcast endpoint to get channel
 *   const res = await fetch('/ai-bridge/stream/broadcast', { ... });
 *   const { channel } = await res.json();
 *   // 2. Create stream with the server-provided channel
 *   const stream = new AiBridgeStream({ mode: 'reverb', channel: channel });
 *   stream.on('text', (content) => { ... });
 *   stream.send({ message: 'Hello', conversation_id: 'conv-1' });
 *
 * Terminal events:
 *   Both 'done' and 'cancelled' are terminal events. Always listen for both
 *   to ensure UI cleanup runs regardless of how the stream ends:
 *   stream.on('done', () => resetUI());
 *   stream.on('cancelled', () => resetUI());
 */
class AiBridgeStream {
    constructor(config = {}) {
        this.mode = config.mode || 'sse';
        this.url = config.url || '/ai-bridge/stream/sse';
        this.broadcastUrl = config.broadcastUrl || '/ai-bridge/stream/broadcast';
        this.channel = config.channel || null;
        this.headers = config.headers || {};
        this.listeners = {};
        this._reader = null;
        this._abortController = null;
        this._generation = 0; // Per-request generation counter to ignore stale events
        this._inactivityTimeout = (config.inactivityTimeout || 60) * 1000; // ms
        this._inactivityTimer = null;

        if (this.mode === 'reverb' && this.channel && typeof window.Echo !== 'undefined') {
            this._listenReverb();
        }
    }

    /**
     * Register an event listener.
     * Events: text, thinking, tool_call, done, error, cancelled, block_start, block_stop, block_delta, raw
     */
    on(event, callback) {
        if (!this.listeners[event]) {
            this.listeners[event] = [];
        }
        this.listeners[event].push(callback);
        return this;
    }

    /**
     * Remove an event listener.
     */
    off(event, callback) {
        if (this.listeners[event]) {
            this.listeners[event] = this.listeners[event].filter(cb => cb !== callback);
        }
        return this;
    }

    /**
     * Send a message. Starts streaming the response.
     */
    send(data) {
        if (this.mode === 'sse') {
            this._sendSSE(data);
        } else if (this.mode === 'reverb') {
            this._sendBroadcast(data);
        }
    }

    /**
     * Abort the current SSE stream.
     * Emits 'cancelled' followed by 'done' so the UI receives terminal events and doesn't hang.
     */
    abort() {
        const gen = this._generation;
        if (this._abortController) {
            this._abortController.abort();
            this._abortController = null;
        }
        // Only emit if this generation hasn't already terminated
        if (gen === this._generation) {
            this._generation++;
            this._emit('cancelled');
            this._emit('done', null);
        }
    }

    /**
     * Clean up resources. Must be called before discarding the instance in SPAs
     * to prevent duplicate Reverb listeners and memory leaks.
     */
    destroy() {
        if (this._abortController) {
            this._abortController.abort();
            this._abortController = null;
        }
        this._clearInactivityTimer();
        if (this.channel && typeof window.Echo !== 'undefined') {
            window.Echo.leave(this.channel);
        }
        this.listeners = {};
    }

    /** @private */
    _emit(event, ...args) {
        if (this.listeners[event]) {
            this.listeners[event].forEach(cb => cb(...args));
        }
    }

    /** @private */
    async _sendSSE(data) {
        // Abort any previous request without emitting events
        if (this._abortController) {
            this._abortController.abort();
            this._abortController = null;
        }

        // New generation — any events from the previous request are now stale
        const gen = ++this._generation;
        this._abortController = new AbortController();
        this._resetInactivityTimer();

        try {
            const response = await fetch(this.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                    ...this.headers,
                },
                body: JSON.stringify(data),
                signal: this._abortController.signal,
            });

            if (!response.ok) {
                if (gen !== this._generation) return;
                this._generation++;
                const errorCode = this._mapHttpStatus(response.status);
                this._emit('error', errorCode, `HTTP ${response.status}`);
                return;
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                // Check if this request is still current
                if (gen !== this._generation) return;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                for (const line of lines) {
                    if (gen !== this._generation) return;

                    const trimmed = line.trim();
                    if (!trimmed || !trimmed.startsWith('data: ')) continue;

                    const payload = trimmed.slice(6);
                    if (payload === '[DONE]') {
                        if (gen === this._generation) {
                            this._generation++;
                            this._emit('done', null);
                        }
                        return;
                    }

                    try {
                        const parsed = JSON.parse(payload);
                        this._handleEvent(parsed, gen);
                    } catch (e) {
                        // Skip unparseable lines
                    }
                }
            }

            // Stream ended without [DONE] — likely a connection drop or truncation.
            // Emit error so the UI knows the response may be incomplete.
            if (gen === this._generation) {
                this._generation++;
                this._emit('error', 'stream_incomplete', 'Stream ended without completion signal. The response may be truncated.');
            }
        } catch (err) {
            if (err.name === 'AbortError') return;
            if (gen !== this._generation) return;
            this._generation++;
            this._emit('error', 'network_error', err.message);
        }
    }

    /** @private */
    async _sendBroadcast(data) {
        // Don't increment generation for broadcast — events arrive via the separate
        // Reverb listener, not via this HTTP response. The generation was already
        // set when the Reverb listener was attached.

        try {
            const response = await fetch(this.broadcastUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...this.headers,
                },
                // Don't send channel — the server derives it from the authenticated user
                body: JSON.stringify(data),
            });

            if (!response.ok) {
                if (this._generation < 1) return;
                this._generation++;
                this._emit('error', 'http_error', `HTTP ${response.status}`);
            }
        } catch (err) {
            this._generation++;
            this._emit('error', 'network_error', err.message);
        }
    }

    /** @private */
    _listenReverb() {
        if (typeof window.Echo === 'undefined') return;

        // Use private channel for authorization (matches server-side PrivateChannel)
        window.Echo.private(this.channel)
            .listen('.ai.stream', (e) => {
                this._handleEvent(e, this._generation);
            });
    }

    /** @private Start or reset the inactivity timer */
    _resetInactivityTimer() {
        this._clearInactivityTimer();
        this._inactivityTimer = setTimeout(() => {
            if (this._generation > 0) {
                const gen = this._generation;
                this._generation++;
                this._emit('error', 'inactivity_timeout', 'No events received within the inactivity timeout period.');
            }
        }, this._inactivityTimeout);
    }

    /** @private Clear the inactivity timer */
    _clearInactivityTimer() {
        if (this._inactivityTimer) {
            clearTimeout(this._inactivityTimer);
            this._inactivityTimer = null;
        }
    }

    /** @private Map HTTP status codes to readable error codes */
    _mapHttpStatus(status) {
        const map = {
            401: 'auth_error',
            403: 'auth_error',
            404: 'not_found',
            422: 'validation_error',
            429: 'rate_limited',
            500: 'server_error',
            502: 'service_unavailable',
            503: 'service_unavailable',
            504: 'gateway_timeout',
        };
        return map[status] || 'http_error';
    }

    /** @private */
    _handleEvent(parsed, gen) {
        // Ignore events from a stale request
        if (gen !== this._generation) return;

        // Reset inactivity timer on each received event
        this._resetInactivityTimer();

        this._emit('raw', parsed);

        const event = parsed.event;
        const data = parsed.data || {};

        switch (event) {
            case 'block_start':
                this._emit('block_start', data.block_type, data.block_index);
                break;
            case 'block_delta':
                if (data.block_type === 'text') {
                    this._emit('text', data.content || '');
                } else if (data.block_type === 'thinking') {
                    this._emit('thinking', data.content || '');
                }
                this._emit('block_delta', data);
                break;
            case 'block_stop':
                this._emit('block_stop', data.block_type, data.block_index);
                break;
            case 'tool_call':
                this._emit('tool_call', data.tool_name, data.parameters || {});
                break;
            case 'cancelled':
                // Server-side cancellation (e.g. bridge mode via Reverb).
                // Emit both 'cancelled' and 'done' for consistent terminal handling.
                if (gen === this._generation) {
                    this._generation++;
                    this._clearInactivityTimer();
                    this._emit('cancelled');
                    this._emit('done', null);
                }
                break;
            case 'done':
                if (gen === this._generation) {
                    this._generation++;
                    this._clearInactivityTimer();
                    this._emit('done', data.usage || null);
                }
                break;
            case 'error':
                if (gen === this._generation) {
                    this._generation++;
                    this._clearInactivityTimer();
                    this._emit('error', data.code || 'unknown', data.message || 'Unknown error');
                }
                break;
        }
    }
}

// Export for module systems, or make available globally
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AiBridgeStream;
} else if (typeof window !== 'undefined') {
    window.AiBridgeStream = AiBridgeStream;
}
