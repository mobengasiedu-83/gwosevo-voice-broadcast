<section><h2>Visual IVR builder</h2><p>Add components, select a card to configure it, and choose destinations to connect your caller's journey. Drag cards to arrange the canvas.</p>
<form method="post" action="{{ route('interactive.save') }}" id="flow-builder">@csrf
<label>Flow name<input name="name" id="flow-name" value="{{ old('name') }}" required maxlength="255"></label>
<input type="hidden" name="source_id" id="source-id" value="{{ old('source_id') }}">
<textarea name="graph" id="flow-graph" hidden>{{ old('graph',json_encode(['start'=>'menu','nodes'=>[['id'=>'menu','type'=>'input','audio_id'=>$audio->first()?->id,'timeout_seconds'=>5,'branches'=>['1'=>'done','9'=>'optout'],'invalid'=>'done','timeout'=>'done'],['id'=>'optout','type'=>'opt_out'],['id'=>'done','type'=>'end']]])) }}</textarea>
<script type="application/json" id="ivr-assets">@json($audio->map(fn($asset)=>['id'=>$asset->id,'name'=>$asset->name])->values())</script>
<script type="application/json" id="ivr-routes">@json($routes->where('active',true)->map(fn($route)=>['id'=>$route->id,'name'=>$route->name])->values())</script>
<div class="ivr-workspace">
<aside class="ivr-palette"><h3>Components</h3><p>Click to add</p>
@foreach(['playback'=>'Play recording','input'=>'Key menu','confirmation'=>'Confirmation','transfer'=>'Transfer','opt_out'=>'Opt-out','timeout'=>'Wait','retry_prompt'=>'Retry prompt','condition'=>'Digit condition','end'=>'End call'] as $type=>$label)
<button type="button" data-add-ivr="{{ $type }}">+ {{ $label }}</button>
@endforeach
<button type="button" id="ivr-layout">Arrange cards</button>
</aside>
<div class="ivr-canvas-scroll"><div id="flow-preview" class="ivr-canvas" aria-label="IVR flow canvas"><svg id="ivr-connections" aria-hidden="true"></svg><div id="ivr-cards"></div></div></div>
<div class="ivr-properties"><h3>Component properties</h3><div id="ivr-properties"></div></div>
</div>
<div class="ivr-validation"><button type="button" id="preview-flow">Validate flow</button><span id="flow-error" role="status" aria-live="polite"></span><ul id="ivr-issues"></ul></div>
@unless(auth()->user()->clientMembership()->exists())<details class="ivr-advanced"><summary>Advanced · generated JSON</summary><pre id="ivr-json"></pre></details>@endunless
<p>Save creates a new draft version. Approval and deployment remain separate. Maximum 30 components and 20 steps per path; loops are not supported.</p>
<div class="form-actions"><button id="ivr-save">Save draft version</button></div>
<noscript><p>The visual builder requires JavaScript. Enable it to design and save a flow.</p></noscript>
</form></section>
<link rel="stylesheet" href="{{ asset('css/ivr-builder.css') }}">
