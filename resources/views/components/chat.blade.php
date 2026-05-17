{{--
    AI Bridge — reference chat component.

    Drop-in ChatGPT-style chat UI for the AI Bridge conversation API. Fully
    optional and overridable (publish the views to restyle). Self-contained:
    pulls Tailwind + Alpine + Echo from a CDN, so the host app needs no build
    toolchain. All real logic lives in the package backend — this component is
    a thin client of the public HTTP API.

    Props:
      api              Base path of the AI Bridge API (default "/ai-bridge").
      thinking-visible Whether thinking blocks are expandable (default true).
                       When false, only the animated "thinking…" line shows.
      reverb-key/-host/-port/-scheme  Reverb connection details (required for
                       bridge mode streaming; omit for BYOK-only setups).
--}}
@props([
    'api' => '/ai-bridge',
    'thinkingVisible' => true,
    'reverbKey' => null,
    'reverbHost' => null,
    'reverbPort' => null,
    'reverbScheme' => 'http',
])

<div
    class="ai-bridge-chat"
    data-api="{{ $api }}"
    data-thinking-visible="{{ $thinkingVisible ? '1' : '0' }}"
    data-reverb-key="{{ $reverbKey }}"
    data-reverb-host="{{ $reverbHost }}"
    data-reverb-port="{{ $reverbPort }}"
    data-reverb-scheme="{{ $reverbScheme }}"
>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.18"></script>
    <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@2.0.2/dist/echo.iife.js"></script>

    <div
        x-data="aiBridgeChat($el.closest('.ai-bridge-chat').dataset)"
        x-init="init()"
        class="flex h-[80vh] min-h-[520px] overflow-hidden rounded-xl border border-gray-200 bg-white text-gray-900 shadow-sm"
    >
        {{-- ── Sidebar ──────────────────────────────────────────────── --}}
        <aside class="flex w-72 flex-col border-r border-gray-200 bg-gray-50">
            <div class="flex items-center justify-between p-3">
                <span class="text-sm font-semibold">Conversations</span>
                <button @click="openNewChat()"
                    class="rounded-md bg-gray-900 px-2.5 py-1 text-xs font-medium text-white hover:bg-gray-700">
                    + New
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-2 pb-2">
                <template x-for="c in conversations" :key="c.id">
                    <div @click="openConversation(c.id)"
                        class="group mb-1 flex cursor-pointer items-center justify-between rounded-md px-2.5 py-2 text-sm"
                        :class="c.id === activeId ? 'bg-gray-200' : 'hover:bg-gray-100'">
                        <div class="min-w-0">
                            <div class="truncate" x-text="c.title || ('Conversation #' + c.id)"></div>
                            <div class="truncate text-[11px] text-gray-500"
                                 x-text="(c.provider || c.mode) + (c.model ? ' · ' + c.model : '')"></div>
                        </div>
                        <button @click.stop="deleteConversation(c.id)"
                            class="ml-2 hidden text-gray-400 hover:text-red-500 group-hover:block">✕</button>
                    </div>
                </template>
                <p x-show="conversations.length === 0" class="px-2 py-4 text-xs text-gray-400">
                    No conversations yet.
                </p>
            </div>

            {{-- Setup / connection panel --}}
            <div class="border-t border-gray-200 p-3">
                <button @click="setupOpen = !setupOpen"
                    class="flex w-full items-center justify-between text-xs font-medium text-gray-600">
                    <span>Setup &amp; connections</span>
                    <span x-text="setupOpen ? '▾' : '▸'"></span>
                </button>
                <div x-show="setupOpen" x-collapse class="mt-2 space-y-2">
                    <template x-for="conn in connections" :key="conn.id">
                        <div class="rounded-md border border-gray-200 bg-white p-2 text-[11px]">
                            <div class="font-medium" x-text="conn.name || conn.type"></div>
                            <div class="text-gray-500" x-text="connSummary(conn)"></div>
                        </div>
                    </template>
                    <button @click="showAddBridge()"
                        class="w-full rounded-md border border-dashed border-gray-300 py-1.5 text-[11px] text-gray-600 hover:bg-gray-100">
                        + Add a CLI bridge
                    </button>
                    <button @click="showAddByok()"
                        class="w-full rounded-md border border-dashed border-gray-300 py-1.5 text-[11px] text-gray-600 hover:bg-gray-100">
                        + Add a BYOK endpoint
                    </button>
                </div>
            </div>
        </aside>

        {{-- ── Main pane ────────────────────────────────────────────── --}}
        <main class="flex flex-1 flex-col">
            <div x-show="!activeId" class="flex flex-1 items-center justify-center text-sm text-gray-400">
                Select or start a conversation.
            </div>

            <template x-if="activeId">
                <div class="flex flex-1 flex-col overflow-hidden">
                    {{-- Header --}}
                    <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2.5">
                        <div class="text-sm font-medium" x-text="activeConversation()?.title || ('Conversation #' + activeId)"></div>
                        <div class="flex items-center gap-2">
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600"
                                  x-text="activeConversation()?.provider || activeConversation()?.mode"></span>
                            <span x-show="activeConversation()?.model"
                                  class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600"
                                  x-text="activeConversation()?.model"></span>
                        </div>
                    </div>

                    <div x-show="toolsStale"
                         class="border-b border-amber-200 bg-amber-50 px-4 py-1.5 text-[11px] text-amber-800">
                        This conversation runs on an older tool set — start a new conversation or reconnect for the freshest tools.
                    </div>

                    {{-- Messages --}}
                    <div class="flex-1 space-y-4 overflow-y-auto p-4" x-ref="scroll">
                        <template x-for="(msg, mi) in messages" :key="mi">
                            <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                                <div class="max-w-[80%] space-y-2">
                                    <template x-for="(block, bi) in msg.blocks" :key="bi">
                                        <div>
                                            {{-- text --}}
                                            <div x-show="block.type === 'text'"
                                                 class="whitespace-pre-wrap rounded-2xl px-4 py-2 text-sm"
                                                 :class="msg.role === 'user' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-900'"
                                                 x-text="block.text"></div>

                                            {{-- thinking --}}
                                            <div x-show="block.type === 'thinking' && thinkingVisible"
                                                 class="rounded-xl border border-violet-200 bg-violet-50 px-3 py-1.5 text-xs text-violet-700">
                                                <button @click="block._open = !block._open" class="font-medium">
                                                    <span x-text="block._open ? '▾' : '▸'"></span> Thinking
                                                </button>
                                                <div x-show="block._open" x-collapse
                                                     class="mt-1 whitespace-pre-wrap text-violet-600" x-text="block.text"></div>
                                            </div>

                                            {{-- tool call --}}
                                            <div x-show="block.type === 'tool_call'"
                                                 class="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs">
                                                <div class="font-medium text-blue-700">
                                                    🔧 <span x-text="block.tool_name"></span>
                                                </div>
                                                <pre class="mt-1 overflow-x-auto text-blue-600"
                                                     x-text="JSON.stringify(block.parameters || {}, null, 2)"></pre>
                                                <div x-show="block.result !== undefined"
                                                     class="mt-1 border-t border-blue-200 pt-1 text-blue-600">
                                                    → <span x-text="formatResult(block.result)"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        {{-- animated "thinking…" line: shown between blocks --}}
                        <div x-show="thinkingPulse" class="flex justify-start">
                            <div class="flex items-center gap-1 rounded-2xl bg-gray-100 px-4 py-2 text-sm text-gray-500">
                                <span>Thinking</span>
                                <span class="animate-pulse">●</span>
                                <span class="animate-pulse [animation-delay:150ms]">●</span>
                                <span class="animate-pulse [animation-delay:300ms]">●</span>
                            </div>
                        </div>

                        <div x-show="streamError" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700"
                             x-text="streamError"></div>
                    </div>

                    {{-- Composer --}}
                    <div class="border-t border-gray-200 p-3">
                        <form @submit.prevent="send()" class="flex gap-2">
                            <input x-model="draft" :disabled="streaming" type="text"
                                   placeholder="Type a message…"
                                   class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none disabled:bg-gray-50">
                            <button type="submit" :disabled="streaming || !draft.trim()"
                                    class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-40">
                                Send
                            </button>
                        </form>
                    </div>
                </div>
            </template>
        </main>

        {{-- ── New-conversation modal ───────────────────────────────── --}}
        <div x-show="modal" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                {{-- new chat --}}
                <template x-if="modal === 'chat'">
                    <div>
                        <h3 class="mb-3 text-base font-semibold">New conversation</h3>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Connection</label>
                        <select x-model="form.connectionId" @change="onConnectionChange()"
                                class="mb-3 w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                            <option value="">— select —</option>
                            <template x-for="conn in connections" :key="conn.id">
                                <option :value="conn.id" x-text="(conn.name || conn.type) + ' (' + conn.type + ')'"></option>
                            </template>
                        </select>

                        <label class="mb-1 block text-xs font-medium text-gray-600">Provider</label>
                        <select x-model="form.provider" @change="form.model = ''"
                                class="mb-3 w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                            <option value="">— select —</option>
                            <template x-for="p in formProviders()" :key="p.name">
                                <option :value="p.name" x-text="p.name"></option>
                            </template>
                        </select>

                        <label class="mb-1 block text-xs font-medium text-gray-600">Model</label>
                        <select x-model="form.model"
                                class="mb-3 w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                            <option value="">— default —</option>
                            <template x-for="m in formModels()" :key="m.id">
                                <option :value="m.id" x-text="m.name || m.id"></option>
                            </template>
                        </select>

                        <label class="mb-1 block text-xs font-medium text-gray-600">System prompt (optional)</label>
                        <textarea x-model="form.systemPrompt" rows="3"
                                  class="mb-4 w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"></textarea>

                        <div class="flex justify-end gap-2">
                            <button @click="modal = null" class="rounded-md px-3 py-1.5 text-sm text-gray-600">Cancel</button>
                            <button @click="createConversation()" :disabled="!form.connectionId || !form.provider"
                                    class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-40">
                                Create
                            </button>
                        </div>
                    </div>
                </template>

                {{-- add bridge --}}
                <template x-if="modal === 'bridge'">
                    <div>
                        <h3 class="mb-3 text-base font-semibold">Add a CLI bridge</h3>
                        <template x-if="!bridgeCommand">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Name (optional)</label>
                                <input x-model="form.name" type="text"
                                       class="mb-4 w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                                <div class="flex justify-end gap-2">
                                    <button @click="modal = null" class="rounded-md px-3 py-1.5 text-sm text-gray-600">Cancel</button>
                                    <button @click="createBridge()"
                                            class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white">Create</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="bridgeCommand">
                            <div>
                                <p class="mb-2 text-xs text-gray-600">Run this on the machine with your Codex / Claude / Gemini CLI:</p>
                                <pre class="mb-3 overflow-x-auto rounded-md bg-gray-900 p-3 text-[11px] text-gray-100"
                                     x-text="bridgeCommand"></pre>
                                <div class="flex justify-end">
                                    <button @click="modal = null; bridgeCommand = null"
                                            class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white">Done</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- add byok --}}
                <template x-if="modal === 'byok'">
                    <div>
                        <h3 class="mb-3 text-base font-semibold">Add a BYOK endpoint</h3>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Name (optional)</label>
                        <input x-model="form.name" type="text"
                               class="mb-3 w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Endpoint</label>
                        <input x-model="form.endpoint" type="text" placeholder="https://api.openai.com"
                               class="mb-3 w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                        <label class="mb-1 block text-xs font-medium text-gray-600">API key</label>
                        <input x-model="form.apiKey" type="password" placeholder="sk-…"
                               class="mb-4 w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm">
                        <div class="flex justify-end gap-2">
                            <button @click="modal = null" class="rounded-md px-3 py-1.5 text-sm text-gray-600">Cancel</button>
                            <button @click="createByok()" :disabled="!form.endpoint || !form.apiKey"
                                    class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-40">
                                Save
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <style>[x-cloak]{display:none!important}</style>

    {{-- Component logic. Registered via alpine:init so it is available before
         Alpine boots; Alpine + the collapse plugin load (deferred) after. --}}
    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('aiBridgeChat', (dataset) => ({
            api: dataset.api || '/ai-bridge',
            thinkingVisible: dataset.thinkingVisible !== '0',
            reverb: {
                key: dataset.reverbKey || null,
                host: dataset.reverbHost || window.location.hostname,
                port: dataset.reverbPort || 8080,
                scheme: dataset.reverbScheme || 'http',
            },

            conversations: [],
            connections: [],
            activeId: null,
            messages: [],
            toolsStale: false,
            setupOpen: false,
            modal: null,
            bridgeCommand: null,
            draft: '',
            streaming: false,
            streamError: null,
            thinkingPulse: false,
            form: { connectionId: '', provider: '', model: '', systemPrompt: '', name: '', endpoint: '', apiKey: '' },
            echo: null,
            sse: null,

            async init() {
                await this.loadConnections();
                await this.loadConversations();
            },

            // ── data loading ──────────────────────────────────────────
            async api_(path, opts = {}) {
                const res = await fetch(this.api + path, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    ...opts,
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.status === 204 ? null : res.json();
            },
            async loadConversations() {
                const data = await this.api_('/conversations');
                this.conversations = data.data || data || [];
            },
            async loadConnections() {
                try {
                    const data = await this.api_('/connections');
                    this.connections = data.connections || [];
                } catch (e) { this.connections = []; }
            },

            // ── conversation list ─────────────────────────────────────
            activeConversation() {
                return this.conversations.find(c => c.id === this.activeId) || null;
            },
            async openConversation(id) {
                this.activeId = id;
                this.streamError = null;
                const data = await this.api_('/conversations/' + id);
                this.toolsStale = !!data.tools_stale;
                this.messages = (data.messages || []).map(m => ({
                    role: m.role,
                    blocks: this.blocksFor(m),
                }));
                this.$nextTick(() => this.scrollDown());
            },
            blocksFor(m) {
                if (Array.isArray(m.blocks) && m.blocks.length) {
                    return m.blocks.map(b => ({ ...b, _open: false }));
                }
                return [{ type: 'text', text: m.content || '' }];
            },
            async deleteConversation(id) {
                await this.api_('/conversations/' + id, { method: 'DELETE' });
                if (this.activeId === id) { this.activeId = null; this.messages = []; }
                await this.loadConversations();
            },

            // ── new conversation modal ────────────────────────────────
            openNewChat() {
                this.form = { connectionId: '', provider: '', model: '', systemPrompt: '', name: '', endpoint: '', apiKey: '' };
                this.modal = 'chat';
            },
            onConnectionChange() { this.form.provider = ''; this.form.model = ''; },
            selectedConnection() {
                return this.connections.find(c => String(c.id) === String(this.form.connectionId)) || null;
            },
            formProviders() { return this.selectedConnection()?.providers || []; },
            formModels() {
                return (this.formProviders().find(p => p.name === this.form.provider)?.models) || [];
            },
            async createConversation() {
                const conn = this.selectedConnection();
                const conv = await this.api_('/conversations', {
                    method: 'POST',
                    body: JSON.stringify({
                        mode: conn?.type === 'byok' ? 'byok' : 'bridge',
                        connection_id: this.form.connectionId,
                        provider: this.form.provider,
                        model: this.form.model || null,
                        system_prompt: this.form.systemPrompt || null,
                    }),
                });
                this.modal = null;
                await this.loadConversations();
                this.openConversation(conv.id);
            },

            // ── connections ───────────────────────────────────────────
            connSummary(conn) {
                const provs = (conn.providers || []).filter(p => p.available !== false).map(p => p.name);
                return provs.length ? provs.join(', ') : 'no providers detected';
            },
            showAddBridge() { this.form.name = ''; this.bridgeCommand = null; this.modal = 'bridge'; },
            showAddByok() { this.form.name = ''; this.form.endpoint = ''; this.form.apiKey = ''; this.modal = 'byok'; },
            async createBridge() {
                const data = await this.api_('/connections', {
                    method: 'POST',
                    body: JSON.stringify({ type: 'bridge', name: this.form.name || null }),
                });
                this.bridgeCommand = data.command;
                await this.loadConnections();
            },
            async createByok() {
                await this.api_('/connections', {
                    method: 'POST',
                    body: JSON.stringify({
                        type: 'byok', name: this.form.name || null,
                        endpoint: this.form.endpoint, api_key: this.form.apiKey,
                    }),
                });
                this.modal = null;
                await this.loadConnections();
            },

            // ── sending + streaming ───────────────────────────────────
            async send() {
                if (this.streaming || !this.draft.trim()) return;
                const text = this.draft.trim();
                this.draft = '';
                this.streamError = null;
                this.messages.push({ role: 'user', blocks: [{ type: 'text', text }] });
                this.assistant = { role: 'assistant', blocks: [] };
                this.messages.push(this.assistant);
                this.current = null;
                this.streaming = true;
                this.thinkingPulse = true;
                this.$nextTick(() => this.scrollDown());

                try {
                    const res = await fetch(this.api + '/conversations/' + this.activeId + '/stream', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'text/event-stream', 'Content-Type': 'application/json' },
                        body: JSON.stringify({ message: text }),
                    });
                    const ct = res.headers.get('content-type') || '';
                    if (ct.includes('application/json')) {
                        // Bridge mode — events arrive over Reverb.
                        const data = await res.json();
                        if (data.error) throw new Error(data.message || data.error);
                        this.listenReverb(data.channel);
                    } else {
                        // BYOK / Managed — read the SSE stream directly.
                        await this.readSse(res);
                    }
                } catch (e) {
                    this.streamError = e.message;
                    this.finishStream();
                }
            },

            async readSse(res) {
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buf = '';
                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buf += decoder.decode(value, { stream: true });
                    const lines = buf.split('\n');
                    buf = lines.pop() || '';
                    for (const line of lines) {
                        const t = line.trim();
                        if (!t.startsWith('data: ')) continue;
                        const payload = t.slice(6);
                        if (payload === '[DONE]') { this.finishStream(); return; }
                        try { this.handleEvent(JSON.parse(payload)); } catch (e) {}
                    }
                }
                this.finishStream();
            },

            listenReverb(channel) {
                if (!this.reverb.key) { this.streamError = 'Reverb is not configured.'; this.finishStream(); return; }
                if (!this.echo) {
                    window.Pusher = window.Pusher || Pusher;
                    this.echo = new Echo({
                        broadcaster: 'reverb',
                        key: this.reverb.key,
                        wsHost: this.reverb.host,
                        wsPort: this.reverb.port,
                        wssPort: this.reverb.port,
                        forceTLS: this.reverb.scheme === 'https',
                        enabledTransports: ['ws', 'wss'],
                        authEndpoint: '/broadcasting/auth',
                    });
                }
                this.echo.private(channel)
                    .listen('.ai.stream', (e) => this.handleEvent(e));
            },

            // ── stream event handling ─────────────────────────────────
            handleEvent(evt) {
                const data = evt.data || {};
                switch (evt.event) {
                    case 'block_start':
                        this.current = { type: data.block_type || 'text', text: '', _open: false };
                        this.assistant.blocks.push(this.current);
                        break;
                    case 'block_delta':
                        this.thinkingPulse = false; // a real block is streaming now
                        if (this.current) this.current.text += (data.content || '');
                        break;
                    case 'block_stop':
                        this.thinkingPulse = true;  // "thinking" again between blocks
                        this.current = null;
                        break;
                    case 'tool_call':
                        this.assistant.blocks.push({
                            type: 'tool_call',
                            tool_name: data.tool_name,
                            parameters: data.parameters || {},
                        });
                        break;
                    case 'done':
                        this.finishStream();
                        break;
                    case 'error':
                        this.streamError = data.message || data.code || 'Stream error';
                        this.finishStream();
                        break;
                    case 'cancelled':
                        this.finishStream();
                        break;
                }
                this.$nextTick(() => this.scrollDown());
            },
            finishStream() {
                this.streaming = false;
                this.thinkingPulse = false;
                this.current = null;
                this.loadConversations();
            },

            // ── misc ──────────────────────────────────────────────────
            formatResult(r) { return typeof r === 'string' ? r : JSON.stringify(r); },
            scrollDown() {
                const el = this.$refs.scroll;
                if (el) el.scrollTop = el.scrollHeight;
            },
        }));
    });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.15.8/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.15.8/dist/cdn.min.js" defer></script>
</div>
