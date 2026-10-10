{{--
    `T-070` · un enlace a un archivo que ya se purgó (`REQ-BOF-043` SP-4): la
    exportación se borra a los siete días. Laravel no trae vista para el `410`,
    y sin ella, con la depuración activa, pinta la página de excepción —que
    además tarda minutos— en lugar de explicarlo.
--}}
@extends('errors::minimal')

@section('title', 'Archivo caducado')
@section('code', '410')
@section('message', $exception->getMessage() !== '' ? $exception->getMessage() : 'Este archivo ya no existe.')
