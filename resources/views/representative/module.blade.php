@extends('secure-access::layouts.app')

@section('title', $definition['title'])
@section('header', __('Acting on behalf of: :name', ['name' => $adult->name]))

@section('content')
    <div class="sa-section">
        <h2 class="sa-title">{{ $definition['title'] }}</h2>
        <a class="sa-link" href="{{ route('secure-access.representative.index') }}">{{ __('Representative workspace') }}</a>
        @include('secure-access::partials.errors', ['messages' => $errors->all()])
        @if (in_array($module.'.create', $scopes, true))
            @include('secure-access::representative.form', ['record' => null])
        @endif
        @foreach ($records as $record)
            <div class="sa-section">
                <dl class="sa-list">
                    @foreach ($record->data as $field => $value)
                        <dt>{{ str($field)->replace('_', ' ')->title() }}</dt>
                        <dd>{{ is_bool($value) ? ($value ? __('Yes') : __('No')) : $value }}</dd>
                    @endforeach
                </dl>
                @if (in_array($module.'.update', $scopes, true))
                    <details>
                        <summary>{{ __('Edit') }}</summary>
                        @include('secure-access::representative.form')
                    </details>
                @endif
                @foreach (['cancel', 'pay', 'submit', 'commit'] as $action)
                    @if (in_array($module.'.'.$action, $scopes, true))
                        <form method="POST" action="{{ route('secure-access.representative.action', [$relationship, $module, $record, $action]) }}">
                            @csrf
                            <button class="sa-btn" type="submit">{{ str($action)->title() }}</button>
                        </form>
                    @endif
                @endforeach
            </div>
        @endforeach
        @if ($records instanceof \Illuminate\Contracts\Pagination\Paginator)
            {{ $records->links() }}
        @endif
    </div>
@endsection
