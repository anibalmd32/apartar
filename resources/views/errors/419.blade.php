@extends('errors::layout')

@section('title', 'La sesión caducó')
@section('code', '419')
@section('heading', 'La página estuvo abierta mucho tiempo')
@section('message', 'Por seguridad, tu sesión caducó. Vuelve a la página anterior, recárgala e inténtalo de nuevo.')
@section('action')
    <a class="btn btn-primary" href="{{ url()->previous() }}">Volver e intentar de nuevo</a>
@endsection
