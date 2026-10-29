@isset($adminIdentities)
<small class="block client-identity">Client: {{ $adminIdentities->label($record) }}</small>
@endisset
