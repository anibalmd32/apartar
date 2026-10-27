<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
apartar
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
Apartar · [apartar.store]({{ config('app.url') }})<br>
[Términos]({{ url('/terminos') }}) · [Privacidad]({{ url('/privacidad') }})<br>
Recibes este correo por un apartado, una cuenta o un negocio en Apartar.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
