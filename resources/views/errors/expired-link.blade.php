@extends('errors::minimal')

@section('title', 'Enlace caducado')
@section('code', '403')
@section('message')
    El enlace de descarga caducó.
    <a href="{{ route('exports.index') }}" style="text-decoration: underline">Descárgalo otra vez desde Exportaciones</a>.
@endsection
