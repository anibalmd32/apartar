@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<span class="brand-mark">a</span><span class="brand-word">{{ $slot }}</span>
</a>
</td>
</tr>
