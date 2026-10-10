{{--
    `T-070` · un enlace de descarga caducado (`REQ-BOF-043` SP-3): `403`, pero
    con la salida —la lista de exportaciones firma uno nuevo cada vez—, no la
    página en blanco de «Invalid signature».
--}}
@extends('errors::minimal')

@section('title', 'Enlace caducado')
@section('code', '403')
@section('message')
    El enlace de descarga caducó.
    <a href="{{ route('exports.index') }}" style="text-decoration: underline">Descárgalo otra vez desde Exportaciones</a>.
@endsection
