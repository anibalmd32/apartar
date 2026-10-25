@extends('errors::minimal')

@section('title', 'Archivo caducado')
@section('code', '410')
@section('message', $exception->getMessage() !== '' ? $exception->getMessage() : 'Este archivo ya no existe.')
