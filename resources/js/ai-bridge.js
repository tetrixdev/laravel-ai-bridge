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
        this._reverbSubscribed = false; // guard against duplicate Echo subscriptions
        this._generation = 0; // Per-request generation counter to ignore stale events
        this._inFlight = false; // explicit in-flight flag (true between send() and a terminal event)
        // Default 120s; configurable via config.inactivityTimeout (seconds). The
        // timer only fires if no events are received in the window.
        this._inactivityTimeout = (config.inactivityTimeout || 120) * 1000; // ms
        this._inactivityTimer = null;

        // _listenReverb() is intentionally NOT called here — it is called from
        // _sendBroadcast() so the generation captured at subscription time
        // matches the generation incoming events are checked against.
    }

    /**
     * Register an event listener.
     * Events: conversation_id, text, thinking, tool_call, done, error, cancelled, block_start, block_stop, block_delta, raw
     *
     * The 'conversation_id' event is emitted as the first event of an SSE response and
     * carries the server-generated conversation ID. Capture it to send follow-up
     * messages in the same multi-turn conversation:
     *   stream.on('conversation_id', (id) => { conversationId = id; });
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
            this._inFlight = false;
            this._emit('cancelled');
            this._emit('done', null);
        }
    }

    /**
     * Clean up resources. Must be called before discarding the instance in SPAs
     * to prevent duplicate Reverb listeners and memory leaks.
     *
     * If a stream is in-flight when destroy() is called, terminal events
     * (cancelled + done) are emitted before listeners are cleared so UI cleanup
     * callbacks still fire and the UI is not left stuck.
     */
    destroy() {
        // _inFlight is true only between send() and a terminal event — a stream
        // that already completed cleanly still has _generation > 0.
        const wasInFlight = this._inFlight;

        if (this._abortController) {
            this._abortController.abort();
            this._abortController = null;
        }
        this._clearInactivityTimer();

        // Emit terminal events before clearing listeners so cleanup callbacks run.
        if (wasInFlight) {
            this._inFlight = false;
            this._emit('cancelled');
            this._emit('done', null);
        }

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
        this._inFlight = true;
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
                this._inFlight = false;
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
                            this._inFlight = false;
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
                this._inFlight = false;
                this._emit('error', 'stream_incomplete', 'Stream ended without completion signal. The response may be truncated.');
            }
        } catch (err) {
            if (err.name === 'AbortError') return;
            if (gen !== this._generation) return;
            this._generation++;
            this._inFlight = false;
            this._emit('error', 'network_error', err.message);
        }
    }

    /** @private */
    _startReverbInactivityTimer() {
        // Inactivity timer for Reverb mode, matching SSE behavior. If no event
        // arrives within the window, emit an error so the UI does not hang.
        this._clearInactivityTimer();
        this._inactivityTimer = setTimeout(() => {
            if (this._generation > 0) {
                this._generation++;
                this._inFlight = false;
                this._emit('error', 'inactivity_timeout', 'No events received within the inactivity timeout period.');
            }
        }, this._inactivityTimeout);
    }

    /** @private */
    async _sendBroadcast(data) {
        // Increment generation for broadcast so each send creates a new "expected" generation.
        // This allows the inactivity timer to track the current request correctly.
        const gen = ++this._generation;
        this._inFlight = true;

        // Subscribe to the Reverb channel here (not in the constructor) so the
        // generation captured at subscription time matches this request's events.
        this._listenReverb();

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
                if (gen !== this._generation) return;
                this._generation++;
                this._inFlight = false;
                const errorCode = this._mapHttpStatus(response.status);
                this._emit('error', errorCode, `HTTP ${response.status}`);
            } else {
                // POST accepted — start the inactivity timer.
                if (gen === this._generation) {
                    this._startReverbInactivityTimer();
                }
            }
        } catch (err) {
            if (gen !== this._generation) return;
            this._generation++;
            this._inFlight = false;
            this._emit('error', 'network_error', err.message);
        }
    }

    /** @private */
    _listenReverb() {
        if (typeof window.Echo === 'undefined') {
            // Echo is not initialized — emit an error event rather than returning
            // silently and leaving the UI frozen with no feedback.
            this._inFlight = false;
            this._emit(
                'error',
                'echo_not_initialized',
                'Laravel Echo is not initialized. Ensure window.Echo is configured before creating AiBridgeStream in reverb mode.'
            );
            return;
        }

        if (!this.channel) return;

        // Subscribe only once. The handler reads the generation captured for the
        // current request so events are matched against the in-flight request;
        // _reverbCapturedGen is updated for each subsequent send().
        this._reverbCapturedGen = this._generation;

        if (this._reverbSubscribed) return;
        this._reverbSubscribed = true;

        // Use private channel for authorization (matches server-side PrivateChannel)
        window.Echo.private(this.channel)
            .listen('.ai.stream', (e) => {
                this._handleEvent(e, this._reverbCapturedGen);
            })
            // Handle channel authorization failures (session expiry, policy
            // changes) — otherwise a 403 from the auth endpoint is silent.
            .error((error) => {
                this._generation++;
                this._inFlight = false;
                this._clearInactivityTimer();
                this._emit('error', 'channel_auth_failed', 'Channel authorization failed. Please refresh the page.');
            });
    }

    /** @private Start or reset the inactivity timer */
    _resetInactivityTimer() {
        this._clearInactivityTimer();
        this._inactivityTimer = setTimeout(() => {
            // Read this._generation at fire time (not captured at reset time)
            // so the timer always acts on the current stream.
            if (this._generation > 0) {
                this._generation++;
                this._inFlight = false;
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
            case 'conversation_id':
                // The server emits 'conversation_id' as the first SSE event;
                // emit it so consuming apps can reuse it for multi-turn messages.
                this._emit('conversation_id', data.conversation_id || '');
                break;
            case 'tool_call':
                this._emit('tool_call', data.tool_name, data.parameters || {});
                break;
            case 'cancelled':
                // Server-side cancellation (e.g. bridge mode via Reverb).
                // Emit both 'cancelled' and 'done' for consistent terminal handling.
                if (gen === this._generation) {
                    this._generation++;
                    this._inFlight = false;
                    this._clearInactivityTimer();
                    this._emit('cancelled');
                    this._emit('done', null);
                }
                break;
            case 'done':
                if (gen === this._generation) {
                    this._generation++;
                    this._inFlight = false;
                    this._clearInactivityTimer();
                    this._emit('done', data.usage || null);
                }
                break;
            case 'error':
                if (gen === this._generation) {
                    this._generation++;
                    this._inFlight = false;
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
