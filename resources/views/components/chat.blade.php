{{--
    <x-ai-bridge::chat /> — reference chat UI for the AI Bridge conversation API.

    A thin wrapper that renders the <ai-bridge-chat> Web Component and loads its
    pre-built bundle. The Web Component uses Shadow DOM, so it CANNOT conflict
    with the host application's CSS framework or JavaScript — no global Tailwind,
    no global Alpine, nothing leaks in or out.

    Fully optional: the package backend is usable without it. To customise the
    UI, build your own against the HTTP API, or fork the component — see the
    "Customizing the chat UI" section of the package README.

    Props:
      api               Base path of the AI Bridge API (default "/ai-bridge").
      thinking-visible  false to hide expandable thinking blocks (default true).
      reverb-key/-host/-port/-scheme  Reverb connection details. Required for
                        bridge-mode streaming; omit for BYOK-only setups.
--}}
@props([
    'api' => '/ai-bridge',
    'thinkingVisible' => true,
    'reverbKey' => null,
    'reverbHost' => null,
    'reverbPort' => null,
    'reverbScheme' => 'http',
])

<ai-bridge-chat
    api="{{ $api }}"
    assets="{{ $api }}/assets"
    thinking-visible="{{ $thinkingVisible ? 'true' : 'false' }}"
    @if($reverbKey) reverb-key="{{ $reverbKey }}" @endif
    @if($reverbHost) reverb-host="{{ $reverbHost }}" @endif
    @if($reverbPort) reverb-port="{{ $reverbPort }}" @endif
    reverb-scheme="{{ $reverbScheme }}"
></ai-bridge-chat>

<script src="{{ $api }}/assets/ai-bridge-chat.js" defer></script>
