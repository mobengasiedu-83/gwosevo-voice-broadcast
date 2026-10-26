<div class="role-permission-grid">
@foreach($permissionGroups as $group=>$permissions)
<fieldset><legend>{{ ucfirst($group) }}</legend>
@foreach($permissions as $permission)
<label><input type="checkbox" name="permissions[]" value="{{ $permission->name }}" @checked(in_array($permission->name, $selected))>{{ str_replace(['.', '_'], ' ', $permission->name) }}</label>
@endforeach
</fieldset>
@endforeach
</div>
