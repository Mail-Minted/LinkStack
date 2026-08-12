        <!-- Your Image Here -->
        @php
            $__avatar = findAvatar($userinfo->id);
        @endphp
        @if(file_exists(base_path($__avatar)))
        <img alt="avatar" id="avatar" class="rounded-avatar fadein" src="{{ url($__avatar) }}" height="160" width="160" style="object-fit: cover;">
        @elseif(file_exists(base_path("assets/linkstack/images/").findFile('avatar')))
        <img alt="avatar" id="avatar" class="fadein" src="{{ url("assets/linkstack/images/")."/".findFile('avatar') }}" height="160" width="160" style="object-fit: cover;">
        @else
        <img alt="avatar" id="avatar" class="fadein" src="{{ asset('assets/linkstack/images/logo.svg') }}" height="160" style="width:auto;min-width:160px;object-fit: cover;">
        @endif