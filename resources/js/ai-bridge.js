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
 *   const stream = new AiBridgeStream({ mode: 'reverb', channel: 'game.123' });
 *   stream.on('text', (content) => { ... });
 *   stream.send({ message: 'Hello', conversation_id: 'conv-1' });
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
        this._finished = false; // Guard against emitting done/error after stream ends

        if (this.mode === 'reverb' && this.channel && typeof window.Echo !== 'undefined') {
            this._listenReverb();
        }
    }

    /**
     * Register an event listener.
     * Events: text, thinking, tool_call, done, error, block_start, block_stop, raw
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
     * Emits a 'cancelled' event so the UI receives a terminal event and doesn't hang.
     */
    abort() {
        if (this._abortController) {
            this._abortController.abort();
            this._abortController = null;
        }
        if (!this._finished) {
            this._finished = true;
            this._emit('cancelled');
            this._emit('done', null);
        }
    }

    /** @private */
    _emit(event, ...args) {
        if (this.listeners[event]) {
            this.listeners[event].forEach(cb => cb(...args));
        }
    }

    /** @private */
    async _sendSSE(data) {
        // Reset state for new request (abort previous without emitting done)
        if (this._abortController) {
            this._abortController.abort();
            this._abortController = null;
        }
        this._finished = false;
        this._abortController = new AbortController();

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
                this._emit('error', 'http_error', `HTTP ${response.status}`);
                return;
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                for (const line of lines) {
                    const trimmed = line.trim();
                    if (!trimmed || !trimmed.startsWith('data: ')) continue;

                    const payload = trimmed.slice(6);
                    if (payload === '[DONE]') {
                        if (!this._finished) {
                            this._finished = true;
                            this._emit('done', null);
                        }
                        return;
                    }

                    try {
                        const parsed = JSON.parse(payload);
                        this._handleEvent(parsed);
                    } catch (e) {
                        // Skip unparseable lines
                    }
                }
            }
        } catch (err) {
            if (err.name !== 'AbortError') {
                this._emit('error', 'network_error', err.message);
            }
        }
    }

    /** @private */
    async _sendBroadcast(data) {
        try {
            const response = await fetch(this.broadcastUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...this.headers,
                },
                body: JSON.stringify({
                    ...data,
                    channel: this.channel,
                }),
            });

            if (!response.ok) {
                this._emit('error', 'http_error', `HTTP ${response.status}`);
            }
        } catch (err) {
            this._emit('error', 'network_error', err.message);
        }
    }

    /** @private */
    _listenReverb() {
        if (typeof window.Echo === 'undefined') return;

        // Use private channel for authorization (matches server-side PrivateChannel)
        window.Echo.private(this.channel)
            .listen('.ai.stream', (e) => {
                this._handleEvent(e);
            });
    }

    /** @private */
    _handleEvent(parsed) {
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
            case 'done':
                if (!this._finished) {
                    this._finished = true;
                    this._emit('done', data.usage || null);
                }
                break;
            case 'error':
                this._emit('error', data.code || 'unknown', data.message || 'Unknown error');
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
