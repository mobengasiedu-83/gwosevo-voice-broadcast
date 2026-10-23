@extends('dashboard.layout')
@php
    $tab = '';
    $shellGateway = app(\App\Services\Dashboard\Health::class)->gateway();
    $alerts = [];
    $workspacePage = true;
@endphp
