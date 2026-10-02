@component('mail::message')
# {{ $title }}

@if($body)
{{ $body }}
@endif

@if($actionUrl)
@component('mail::button', ['url' => $actionUrl])
{{ __('Open platform') }}
@endcomponent
@endif

{{ __('Sent from the learning platform') }}.
@endcomponent
