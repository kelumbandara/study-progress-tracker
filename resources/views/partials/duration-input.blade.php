{{-- Hours + minutes inputs; $name is the field prefix, $minutes the stored value. --}}
@php
    $h = old("{$name}_h", $minutes !== null ? intdiv($minutes, 60) : 0);
    $m = old("{$name}_m", $minutes !== null ? $minutes % 60 : 0);
@endphp
<div class="input-group">
    <input type="number" inputmode="numeric" min="0" max="24" name="{{ $name }}_h" id="{{ $name }}_h" value="{{ $h }}"
           class="form-control duration-part @error("{$name}_h") is-invalid @enderror" aria-label="{{ $label }} hours">
    <div class="input-group-append input-group-prepend"><span class="input-group-text">h</span></div>
    <input type="number" inputmode="numeric" min="0" max="59" name="{{ $name }}_m" id="{{ $name }}_m" value="{{ $m }}"
           class="form-control duration-part @error("{$name}_m") is-invalid @enderror" aria-label="{{ $label }} minutes">
    <div class="input-group-append"><span class="input-group-text">min</span></div>
</div>
