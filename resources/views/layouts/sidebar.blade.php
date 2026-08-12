@php
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Models\User;
$usrhandl = Auth::user()->littlelink_name;
@endphp
<!doctype html>
@include('layouts.lang')
<html>
  <head>
    <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
      <title>{{env('APP_NAME')}}</title>

      <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/detect-dark-mode.js')}}"></script>
      
      <base href="{{url()->current()}}" />

	  @include('layouts.analytics')
	  @stack('sidebar-stylesheets')
    @include('layouts.notifications')

    @php
    // Update the 'updated_at' timestamp for the currently authenticated user
    if (auth()->check()) {
        $user = auth()->user();
        $user->touch();
    }
    @endphp

      <!-- Favicon: the signed-in customer's own icon, falling back to the site's -->
      <x-tab-icon />
      
      <!-- Library / Plugin Css Build -->
      <link rel="stylesheet" href="{{asset('assets/css/core/libs.min.css')}}" />
      
      <!-- Aos Animation Css -->
      <link rel="stylesheet" href="{{asset('assets/vendor/aos/dist/aos.css')}}" />
      
      @include('layouts.fonts')
      
      <!-- Hope Ui Design System Css -->
      <link rel="stylesheet" href="{{asset('assets/css/hope-ui.min.css?v=2.0.0')}}" />
      
      <!-- Custom Css -->
      <link rel="stylesheet" href="{{asset('assets/css/custom.min.css?v=2.0.0')}}" />
      
      <!-- Dark Css -->
      <link rel="stylesheet" href="{{asset('assets/css/dark.min.css')}}" />
      
      <!-- Customizer Css -->
            @if(file_exists(base_path("assets/dashboard-themes/dashboard.css")))
      <link rel="stylesheet" href="{{asset('assets/dashboard-themes/dashboard.css')}}" />
      @else
      <link rel="stylesheet" href="{{asset('assets/css/customizer.min.css')}}" />
      @endif
      
      <!-- RTL Css -->
      <link rel="stylesheet" href="{{asset('assets/css/rtl.min.css')}}" />
      
	  <meta name="csrf-token" content="{{ csrf_token() }}">
	  <link rel="stylesheet" href="{{ asset('assets/linkstack/css/hover-min.css') }}">
	  <link rel="stylesheet" href="{{ asset('assets/linkstack/css/animate.css') }}">
	  <link rel="stylesheet" href="{{ asset('assets/external-dependencies/bootstrap-icons.css') }}">

	  {{-- Modal accessibility + dark mode sync.

	       Bootstrap 4.3 ships no dark-mode awareness for its modal
	       chrome (default background: #fff). The Mail Minted
	       dashboard.css scopes its dark theme with a `.dark` class
	       on <body> (toggled by LinkStack's in-app Color Mode
	       picker in the sidebar settings). We follow the same
	       trigger so modals flip in sync with the rest of the
	       dashboard — toggling Light/Dark in-app switches both.
	       Previously these rules keyed off prefers-color-scheme,
	       which is OS-level and didn't track LinkStack's own
	       toggle, leaving modals stuck on the wrong theme. --}}
	  <style>
	    /* Force tall modals to scroll their body instead of growing
	       past the viewport. BS4's .modal-dialog-scrollable alone is
	       finicky — pinning an explicit max-height on .modal-body
	       makes it bullet-proof. The 220px accounts for header,
	       footer, and the dialog's top/bottom margins. */
	    .modal-dialog-scrollable .modal-body {
	      max-height: calc(100vh - 220px);
	      overflow-y: auto;
	    }
	    body.dark .modal-content {
	      background-color: #1f2329 !important;
	      color: #e9ecef !important;
	      border: 1px solid #2a2e33 !important;
	    }
	    body.dark .modal-header,
	    body.dark .modal-footer {
	      border-color: #2a2e33 !important;
	    }
	    body.dark .modal-title {
	      color: #e9ecef !important;
	    }
	    body.dark .modal-body,
	    body.dark .modal-body label,
	    body.dark .modal-body .form-label,
	    body.dark .modal-body .text-muted,
	    body.dark .modal-body small {
	      color: #cfd3d8 !important;
	    }
	    body.dark .modal-body .form-control,
	    body.dark .modal-body .form-select {
	      background-color: #2a2e33;
	      color: #e9ecef;
	      border-color: #3a3f45;
	    }
	    body.dark .modal-body .form-control::placeholder {
	      color: #7a8089;
	    }
	    body.dark .modal-body .input-group-text {
	      background-color: #2a2e33;
	      color: #cfd3d8;
	      border-color: #3a3f45;
	    }
	    body.dark .modal-header .btn-close,
	    body.dark .modal-header .close {
	      filter: invert(1) grayscale(100%) brightness(2);
	    }
	  </style>

  </head>
  <body class="  {{ request()->boolean('embed') ? 'mm-embed' : '' }}">
    <!-- loader Start -->
    <div id="loading">
      <div class="loader simple-loader">
          <div class="loader-body"></div>
      </div>    </div>
    <!-- loader END -->
    
    <aside class="sidebar sidebar-default sidebar-white sidebar-base navs-rounded-all ">
        <div class="sidebar-header d-flex align-items-center justify-content-start">
            <a href="{{ route('panelIndex') }}" class="navbar-brand">
                
                <!--Logo start-->
                <div class="logo-main">
                @if(file_exists(base_path("assets/linkstack/images/").findFile('avatar')))
                <div class="logo-normal">
                  <img class="img logo" src="{{ asset('assets/linkstack/images/'.findFile('avatar')) }}" style="width:auto;height:30px;">
              </div>
              <div class="logo-mini">
                <img class="img logo" src="{{ asset('assets/linkstack/images/'.findFile('avatar')) }}" style="width:auto;height:30px;">
              </div>
                @else
                <div class="logo-normal">
                  <img class="img logo" type="image/svg+xml" src="{{ asset('assets/linkstack/images/logo.svg') }}" width="30px" height="30px">
              </div>
              <div class="logo-mini">
                <img class="img logo" type="image/svg+xml" src="{{ asset('assets/linkstack/images/logo.svg') }}" width="30px" height="30px">
              </div>
                @endif
                </div>
                <!--logo End-->
                
                <h4 class="logo-title">{{env('APP_NAME')}}</h4>
            </a>
            <div class="sidebar-toggle" data-toggle="sidebar" data-active="true">
                <i class="icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4.25 12.2744L19.25 12.2744" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                        <path d="M10.2998 18.2988L4.2498 12.2748L10.2998 6.24976" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                </i>
            </div>
        </div>
        <div class="sidebar-body pt-0 data-scrollbar">
            <div class="sidebar-list">
                <!-- Sidebar Menu Start -->
                <ul class="navbar-nav iq-main-menu" id="sidebar-menu">
                    {{-- "Home" section divider removed — the sidebar is short
                         enough that section labels are just clutter. --}}
                    <li class="nav-item">
                        <a class="nav-link {{ Request::segment(1) == 'dashboard' ? 'active' : 'bg-soft-primary'}}" aria-current="page" href="{{ route('panelIndex') }}">
                            <i class="icon">
                                <svg width="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="icon-20">
                                  <path fill-rule="evenodd" clip-rule="evenodd" d="M7.33049 2.00049H16.6695C20.0705 2.00049 21.9905 3.92949 22.0005 7.33049V16.6705C22.0005 20.0705 20.0705 22.0005 16.6695 22.0005H7.33049C3.92949 22.0005 2.00049 20.0705 2.00049 16.6705V7.33049C2.00049 3.92949 3.92949 2.00049 7.33049 2.00049ZM12.0495 17.8605C12.4805 17.8605 12.8395 17.5405 12.8795 17.1105V6.92049C12.9195 6.61049 12.7705 6.29949 12.5005 6.13049C12.2195 5.96049 11.8795 5.96049 11.6105 6.13049C11.3395 6.29949 11.1905 6.61049 11.2195 6.92049V17.1105C11.2705 17.5405 11.6295 17.8605 12.0495 17.8605ZM16.6505 17.8605C17.0705 17.8605 17.4295 17.5405 17.4805 17.1105V13.8305C17.5095 13.5095 17.3605 13.2105 17.0895 13.0405C16.8205 12.8705 16.4805 12.8705 16.2005 13.0405C15.9295 13.2105 15.7805 13.5095 15.8205 13.8305V17.1105C15.8605 17.5405 16.2195 17.8605 16.6505 17.8605ZM8.21949 17.1105C8.17949 17.5405 7.82049 17.8605 7.38949 17.8605C6.95949 17.8605 6.59949 17.5405 6.56049 17.1105V10.2005C6.53049 9.88949 6.67949 9.58049 6.95049 9.41049C7.21949 9.24049 7.56049 9.24049 7.83049 9.41049C8.09949 9.58049 8.25049 9.88949 8.21949 10.2005V17.1105Z" fill="currentColor"></path>
                                </svg>
                            </i>
                            <span class="item-name">{{__('messages.Dashboard')}}</span>
                        </a>
                    </li>
                    @if(auth()->user()->role == 'admin')
                    <li class="nav-item static-item">
                        <a class="nav-link static-item disabled" href="#" tabindex="-1">
                            <span class="default-icon">{{__('messages.Administration')}}</span>
                            <span class="mini-icon">-</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="collapse" href="#utilities-error" role="button" aria-expanded="false" aria-controls="utilities-error">
                            <i class="icon">
								<svg class="icon-20" width="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path fill-rule="evenodd" clip-rule="evenodd" d="M20.4023 13.58C20.76 13.77 21.036 14.07 21.2301 14.37C21.6083 14.99 21.5776 15.75 21.2097 16.42L20.4943 17.62C20.1162 18.26 19.411 18.66 18.6855 18.66C18.3278 18.66 17.9292 18.56 17.6022 18.36C17.3365 18.19 17.0299 18.13 16.7029 18.13C15.6911 18.13 14.8429 18.96 14.8122 19.95C14.8122 21.1 13.872 22 12.6968 22H11.3069C10.1215 22 9.18125 21.1 9.18125 19.95C9.16081 18.96 8.31259 18.13 7.30085 18.13C6.96361 18.13 6.65702 18.19 6.40153 18.36C6.0745 18.56 5.66572 18.66 5.31825 18.66C4.58245 18.66 3.87729 18.26 3.49917 17.62L2.79402 16.42C2.4159 15.77 2.39546 14.99 2.77358 14.37C2.93709 14.07 3.24368 13.77 3.59115 13.58C3.87729 13.44 4.06125 13.21 4.23498 12.94C4.74596 12.08 4.43937 10.95 3.57071 10.44C2.55897 9.87 2.23194 8.6 2.81446 7.61L3.49917 6.43C4.09191 5.44 5.35913 5.09 6.38109 5.67C7.27019 6.15 8.425 5.83 8.9462 4.98C9.10972 4.7 9.20169 4.4 9.18125 4.1C9.16081 3.71 9.27323 3.34 9.4674 3.04C9.84553 2.42 10.5302 2.02 11.2763 2H12.7172C13.4735 2 14.1582 2.42 14.5363 3.04C14.7203 3.34 14.8429 3.71 14.8122 4.1C14.7918 4.4 14.8838 4.7 15.0473 4.98C15.5685 5.83 16.7233 6.15 17.6226 5.67C18.6344 5.09 19.9118 5.44 20.4943 6.43L21.179 7.61C21.7718 8.6 21.4447 9.87 20.4228 10.44C19.5541 10.95 19.2475 12.08 19.7687 12.94C19.9322 13.21 20.1162 13.44 20.4023 13.58ZM9.10972 12.01C9.10972 13.58 10.4076 14.83 12.0121 14.83C13.6165 14.83 14.8838 13.58 14.8838 12.01C14.8838 10.44 13.6165 9.18 12.0121 9.18C10.4076 9.18 9.10972 10.44 9.10972 12.01Z" fill="currentColor"></path>
								</svg>
							</i>
                            <span class="item-name">{{__('messages.Admin')}}</span>
                            <i class="right-icon">
                                <svg class="icon-18" xmlns="http://www.w3.org/2000/svg" width="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </i>
                        </a>
                        <ul class="sub-nav collapse" id="utilities-error" data-bs-parent="#sidebar-menu">
                            <li class="nav-item">
                                <a class="nav-link {{ Request::segment(2) == 'config' ? 'active' : ''}}" href="{{ url('admin/config') }}">
                                  <i class="bi bi-wrench-adjustable-circle-fill"></i>
                                    <span class="item-name">{{__('messages.Config')}}</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link {{ Request::segment(2) == 'users' ? 'active' : ''}}" href="{{ url('admin/users/all') }}">
                                  <i class="bi bi-people-fill"></i>
                                    <span class="item-name">{{__('messages.Manage Users')}}</span>
                                </a>
                            </li>
							<li class="nav-item">
                                <a class="nav-link {{ Request::segment(2) == 'pages' ? 'active' : ''}}" href="{{ url('admin/pages') }}">
                                  <i class="bi bi-collection-fill"></i>
                                    <span class="item-name">{{__('messages.Footer Pages')}}</span>
                                </a>
                            </li>
							<li class="nav-item">
                                <a class="nav-link {{ Request::segment(2) == 'site' ? 'active' : ''}}" href="{{ url('admin/site') }}">
                                  <i class="bi bi-palette-fill"></i>
                                    <span class="item-name">{{__('messages.Site Customization')}}</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                    @endif
                    {{-- "Personalization" section divider removed — clutter. --}}
                    {{-- Unified studio editor — replaces the four separate
                         items (Blocks / Social icons / Page info / Appearance)
                         with one entry. Active for /studio/edit and the old
                         segments that now redirect into it, so the highlight
                         stays put on a bookmarked old URL mid-redirect. --}}
                    <li class="nav-item">
                        <a class="nav-link {{ in_array(Request::segment(2), ['edit','links','social-icons','page','appearance']) ? 'active' : ''}}" href="{{ url('/studio/edit') }}">
                            <i class="icon">
                                 <svg class="icon-20" width="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path fill-rule="evenodd" clip-rule="evenodd" d="M16.6653 2.01034C18.1038 1.92043 19.5224 2.41991 20.5913 3.3989C21.5703 4.46779 22.0697 5.88633 21.9898 7.33483V16.6652C22.0797 18.1137 21.5703 19.5322 20.6013 20.6011C19.5323 21.5801 18.1038 22.0796 16.6653 21.9897H7.33487C5.88636 22.0796 4.46781 21.5801 3.39891 20.6011C2.41991 19.5322 1.92043 18.1137 2.01034 16.6652V7.33483C1.92043 5.88633 2.41991 4.46779 3.39891 3.3989C4.46781 2.41991 5.88636 1.92043 7.33487 2.01034H16.6653ZM10.9811 16.845L17.7042 10.102C18.3136 9.4826 18.3136 8.48364 17.7042 7.87427L16.4056 6.57561C15.7862 5.95625 14.7872 5.95625 14.1679 6.57561L13.4985 7.25491C13.3986 7.35481 13.3986 7.52463 13.4985 7.62453C13.4985 7.62453 15.0869 9.20289 15.1169 9.24285C15.2268 9.36273 15.2967 9.52256 15.2967 9.70238C15.2967 10.062 15.007 10.3617 14.6374 10.3617C14.4675 10.3617 14.3077 10.2918 14.1978 10.1819L12.5295 8.5236C12.4496 8.44368 12.3098 8.44368 12.2298 8.5236L7.46474 13.2887C7.13507 13.6183 6.94527 14.0579 6.93528 14.5274L6.87534 16.8949C6.87534 17.0248 6.9153 17.1447 7.00521 17.2346C7.09512 17.3245 7.21499 17.3744 7.34486 17.3744H9.69245C10.172 17.3744 10.6315 17.1846 10.9811 16.845Z" fill="currentColor"></path></svg>
                            </i>
                            <span class="item-name">Edit page</span>
                        </a>
                    </li>
                    {{-- Theme SELECTION is now a tab of the unified editor
                         ("Edit page" → Themes). This nav item is admin-only
                         and links to /studio/theme purely for the theme-zip
                         management/upload surface (item 12a). Customers never
                         see it; non-admins hitting /studio/theme are
                         redirected to the editor's Themes tab by the route. --}}
                    @if(auth()->check() && auth()->user()->role == 'admin')
                    <li class="nav-item">
                        <a class="nav-link {{ Request::segment(2) == 'theme' ? 'active' : ''}}" href="{{ url('/studio/theme') }}">
                            <i class="icon">
                                 <svg class="icon-20" width="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                               <path fill-rule="evenodd" clip-rule="evenodd" d="M7.63751 3.39549C5.06051 3.39549 3.39551 5.16249 3.39551 7.88849V16.1025C3.39551 16.8675 3.53751 17.5505 3.78051 18.1415C3.791 18.129 4.01986 17.8501 4.3184 17.4863C4.90188 16.7752 5.75156 15.7398 5.75751 15.7345C6.44951 14.9445 7.74851 13.7665 9.45351 14.4795C9.82712 14.6344 10.1592 14.8466 10.4649 15.042C10.4947 15.061 10.5242 15.0799 10.5535 15.0985C11.1265 15.4815 11.4635 15.6615 11.8135 15.6315C11.9585 15.6115 12.0945 15.5685 12.2235 15.4885C12.7101 15.1885 13.9718 13.4009 14.3496 12.8656C14.405 12.7871 14.4414 12.7355 14.4535 12.7195C15.5435 11.2995 17.2235 10.9195 18.6235 11.7595C18.8115 11.8715 20.1585 12.8125 20.6045 13.1905V7.88849C20.6045 5.16249 18.9395 3.39549 16.3535 3.39549H7.63751ZM16.3535 2.00049C19.7305 2.00049 21.9995 4.36249 21.9995 7.88849V16.1025C21.9995 16.1912 21.9902 16.2743 21.9809 16.3574C21.9744 16.4159 21.9678 16.4742 21.9645 16.5345C21.9624 16.5709 21.9613 16.6073 21.9603 16.6438C21.9589 16.6923 21.9575 16.7409 21.9535 16.7895C21.9515 16.8085 21.9478 16.8267 21.944 16.845C21.9403 16.8632 21.9365 16.8815 21.9345 16.9005C21.9015 17.2145 21.8505 17.5145 21.7795 17.8055C21.7627 17.8782 21.7433 17.9483 21.7238 18.0191L21.7195 18.0345C21.6395 18.3165 21.5455 18.5855 21.4325 18.8425C21.4127 18.8857 21.3918 18.9278 21.3709 18.9699C21.357 18.998 21.3431 19.0261 21.3295 19.0545C21.2075 19.2995 21.0755 19.5345 20.9225 19.7525C20.8942 19.7928 20.8641 19.8307 20.8339 19.8685C20.814 19.8936 20.794 19.9186 20.7745 19.9445C20.6155 20.1505 20.4495 20.3475 20.2615 20.5265C20.224 20.5622 20.1834 20.5948 20.1428 20.6275C20.1175 20.6479 20.0921 20.6683 20.0675 20.6895C19.8745 20.8555 19.6775 21.0145 19.4605 21.1505C19.4132 21.1802 19.3628 21.2052 19.3127 21.2301C19.2803 21.2462 19.2479 21.2622 19.2165 21.2795C18.9955 21.4015 18.7725 21.5205 18.5295 21.6125C18.4711 21.6347 18.4088 21.6508 18.3465 21.6669C18.3021 21.6783 18.2577 21.6898 18.2145 21.7035C18.1929 21.7102 18.1713 21.7169 18.1497 21.7236C17.9326 21.7912 17.7162 21.8585 17.4825 21.8985C17.3471 21.9222 17.2034 21.9313 17.0596 21.9405C16.9974 21.9444 16.9351 21.9484 16.8735 21.9535C16.8073 21.9584 16.7423 21.9664 16.6773 21.9744C16.5716 21.9874 16.4656 22.0005 16.3535 22.0005H7.63751C7.26151 22.0005 6.90251 21.9625 6.55551 21.9055C6.54251 21.9035 6.53051 21.9015 6.51851 21.8995C5.16551 21.6665 4.04251 21.0135 3.25551 20.0285C3.25005 20.0285 3.2479 20.0248 3.24504 20.0199C3.24319 20.0167 3.24105 20.013 3.23751 20.0095C2.44651 19.0135 1.99951 17.6745 1.99951 16.1025V7.88849C1.99951 4.36249 4.27051 2.00049 7.63751 2.00049H16.3535ZM11.0001 8.51505C11.0001 9.87 9.86639 11.0001 8.50496 11.0001C7.30825 11.0001 6.2879 10.1257 6.05922 8.99372C6.02143 8.82387 6.00011 8.64919 6.00011 8.46872C6.00011 7.10412 7.10864 6.00009 8.47879 6.00009C9.17647 6.00009 9.80825 6.29347 10.2608 6.76152C10.7152 7.21317 11.0001 7.83564 11.0001 8.51505Z" fill="currentColor"></path></svg>
                            </i>
                            <span class="item-name">Manage themes</span>
                        </a>
                    </li>
                    @endif
                    {{-- Integrations (Stripe / Analytics / Redirect + data
                         export-import) — the /studio/profile page, renamed
                         from "Account" now that auth/account controls are
                         Mail Minted's. Lives in the sidebar to mirror the
                         Mail Minted portal. --}}
                    <li class="nav-item">
                        <a class="nav-link {{ Request::segment(2) == 'profile' ? 'active' : ''}}" href="{{ url('/studio/profile') }}">
                            <i class="bi bi-plug-fill"></i>
                            <span class="item-name">Integrations</span>
                        </a>
                    </li>
                    {{-- Styling nav item removed — replaced by the compact
                         light/dark/system selector pinned in the sidebar
                         footer (above Sign out). --}}
                        </ul>
                    </li>
                </ul>
                <!-- Sidebar Menu End -->        </div>
        </div>
        @once
        <style>
            /* Pin the footer (theme selector + Sign out) to the bottom of
               the sidebar. The sidebar is position:fixed; top:0; bottom:0
               (full viewport height) but display:block, so the footer
               otherwise just trails the nav. Absolute bottom:0 sticks it
               to the bottom; the scroll area is shortened to reserve room
               so nav items never tuck under it. */
            .sidebar .sidebar-footer {
                position: absolute;
                left: 0;
                right: 0;
                bottom: 0;
                padding: 10px 12px;
                border-top: 1px solid rgba(128, 128, 128, 0.15);
            }
            .sidebar .data-scrollbar {
                max-height: calc(100vh - 120px) !important;
            }
            /* Current-mode highlight (expanded view). Custom class, not
               Bootstrap's .active — see the setMode() comment for why. */
            .mm-theme-select .btn.mm-active {
                background-color: rgba(128, 128, 128, 0.2);
                font-weight: 600;
            }

            /* Collapsed (mini) sidebar: hide the Sign out label (icon
               only), and shrink the theme selector to a single icon
               showing the current mode — a click cycles through the
               modes (see the script below). */
            .sidebar.sidebar-mini .sidebar-footer .item-name { display: none; }
            .sidebar.sidebar-mini .sidebar-footer .nav-link { justify-content: center; }
            .sidebar.sidebar-mini .sidebar-footer .nav-link i { margin: 0 !important; }
            .sidebar.sidebar-mini .mm-theme-select { padding-left: 0 !important; padding-right: 0 !important; }
            /* Collapsed: show only the button matching the current mode.
               Driven by data-mode on the container (set by the script from
               localStorage 'color-mode'), NOT by hope-ui's .active class —
               hope-ui doesn't reliably mark these buttons active, which is
               why relying on .active hid all three. */
            .sidebar.sidebar-mini .mm-theme-select .btn { display: none; }
            .sidebar.sidebar-mini .mm-theme-select[data-mode="light"] [data-value="light"],
            .sidebar.sidebar-mini .mm-theme-select[data-mode="dark"]  [data-value="dark"],
            .sidebar.sidebar-mini .mm-theme-select[data-mode="auto"]  [data-value="auto"] {
                display: flex !important;
                width: 100%;
            }
        </style>
        @endonce
        <div class="sidebar-footer">
            {{-- Light / Dark / System theme selector — replaces the old
                 Styling offcanvas. Uses hope-ui.js's color-mode setting
                 (data-setting="color-mode"), the same mechanism the old
                 Scheme toggle used, so it flips the dashboard theme +
                 persists the choice. Pinned above Sign out. --}}
            <div class="mm-theme-select d-flex gap-1 px-3 pb-2" data-mode="auto">
                <div class="btn btn-sm btn-border flex-fill" role="button" title="Light" data-setting="color-mode" data-name="color" data-value="light">
                    <i class="bi bi-sun-fill"></i>
                </div>
                <div class="btn btn-sm btn-border flex-fill" role="button" title="Dark" data-setting="color-mode" data-name="color" data-value="dark">
                    <i class="bi bi-moon-stars-fill"></i>
                </div>
                <div class="btn btn-sm btn-border flex-fill" role="button" title="System" data-setting="color-mode" data-name="color" data-value="auto">
                    <i class="bi bi-display-fill"></i>
                </div>
            </div>
            {{-- Sign out pinned at the bottom of the sidebar, mirroring the
                 Mail Minted portal. Routes through the SSO logout bridge so
                 signing out here also clears the Mail Minted (Supabase)
                 session. --}}
            <a class="nav-link d-flex align-items-center px-3 py-2" href="{{ route('mailminted.sso.logout') }}">
                <i class="bi bi-box-arrow-in-left me-2"></i>
                <span class="item-name">Sign out</span>
            </a>
        </div>
        @push('sidebar-scripts')
        <script nonce="{{ csp_nonce() }}">
        (function () {
            var sel = document.querySelector('.mm-theme-select');
            if (!sel) return;
            var ORDER = ['light', 'dark', 'auto'];
            var buttons = {};
            ORDER.forEach(function (m) { buttons[m] = sel.querySelector('[data-value="' + m + '"]'); });

            function isMini() {
                var a = document.querySelector('.sidebar');
                return !!(a && a.classList.contains('sidebar-mini'));
            }
            function storedMode() {
                // detect-dark-mode.js keeps localStorage 'color-mode' in
                // {light,dark,auto}; default auto if missing/invalid.
                var s = localStorage.getItem('color-mode');
                return ORDER.indexOf(s) === -1 ? 'auto' : s;
            }
            // Single source of truth for which button shows when collapsed
            // (data-mode drives the CSS) and which is highlighted when
            // expanded (.active). Managed here rather than relying on
            // hope-ui, which doesn't mark these buttons active.
            function setMode(mode) {
                if (ORDER.indexOf(mode) === -1) mode = 'auto';
                sel.setAttribute('data-mode', mode);
                // Use mm-active (NOT Bootstrap's .active): hope-ui's sidebar
                // init does sidebar.querySelectorAll('.active') and assumes
                // every match sits inside a <ul> menu — these footer buttons
                // don't, so a plain .active here crashes it (white page).
                ORDER.forEach(function (m) {
                    if (buttons[m]) buttons[m].classList.toggle('mm-active', m === mode);
                });
            }

            setMode(storedMode()); // initialise from the stored mode

            // Keep in sync on any mode-button click (real or synthetic).
            sel.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-setting="color-mode"]');
                if (btn) setMode(btn.getAttribute('data-value'));
            });

            // Collapsed: the single visible icon cycles to the next mode
            // (light -> dark -> auto). Capture-phase interception runs
            // before hope-ui's delegated handler; the guard stops the
            // synthetic click from looping. Expanded (all 3 shown) is
            // untouched — each button selects its mode directly.
            var cycling = false;
            sel.addEventListener('click', function (e) {
                if (cycling || !isMini()) return;
                var btn = e.target.closest('[data-setting="color-mode"]');
                if (!btn) return;
                e.preventDefault();
                e.stopImmediatePropagation();
                var cur = sel.getAttribute('data-mode') || 'auto';
                var next = ORDER[(ORDER.indexOf(cur) + 1) % ORDER.length];
                if (buttons[next]) {
                    cycling = true;
                    buttons[next].click();
                    cycling = false;
                }
            }, true);
        })();
        </script>
        @endpush
    </aside>    <main class="main-content">
      <div class="position-relative iq-banner">
        <!--Nav Start-->
        <nav class="nav navbar navbar-expand-lg navbar-light iq-navbar">
          <div class="container-fluid navbar-inner">
            <a href="{{ route('panelIndex') }}" class="navbar-brand">
                
                <!--Logo start-->
                <div class="logo-main">
                  @if(file_exists(base_path("assets/linkstack/images/").findFile('avatar')))
                  <div class="logo-normal">
                    <img class="img logo" src="{{ asset('assets/linkstack/images/'.findFile('avatar')) }}" style="width:auto;height:30px;">
                </div>
                <div class="logo-mini">
                  <img class="img logo" src="{{ asset('assets/linkstack/images/'.findFile('avatar')) }}" style="width:auto;height:30px;">
                </div>
                  @else
                  <div class="logo-normal">
                    <img class="img logo" type="image/svg+xml" src="{{ asset('assets/linkstack/images/logo.svg') }}" width="30px" height="30px">
                </div>
                <div class="logo-mini">
                  <img class="img logo" type="image/svg+xml" src="{{ asset('assets/linkstack/images/logo.svg') }}" width="30px" height="30px">
                </div>
                  @endif
                  </div>
                <!--logo End-->
                
                
                <h4 class="logo-title">{{env('APP_NAME')}}</h4>
            </a>
            <div class="sidebar-toggle" data-toggle="sidebar" data-active="true">
                <i class="icon">
                 <svg  width="20px" class="icon-20" viewBox="0 0 24 24">
                    <path fill="currentColor" d="M4,11V13H16L10.5,18.5L11.92,19.92L19.84,12L11.92,4.08L10.5,5.5L16,11H4Z" />
                </svg>
                </i>
            </div>
            {{-- <div class="input-group search-input">
              <span class="input-group-text" id="search-input">
                <svg class="icon-18" width="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="11.7669" cy="11.7666" r="8.98856" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></circle>
                    <path d="M18.0186 18.4851L21.5426 22" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                </svg>
              </span>
              <input type="search" class="form-control" placeholder="Search...">
            </div> --}}
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
              <span class="navbar-toggler-icon">
                  <span class="mt-2 navbar-toggler-bar bar1"></span>
                  <span class="navbar-toggler-bar bar2"></span>
                  <span class="navbar-toggler-bar bar3"></span>
                </span>
            </button>
            <div class="collapse navbar-collapse" id="navbarSupportedContent">
              <ul class="mb-2 navbar-nav ms-auto align-items-center navbar-list mb-lg-0">
                <li class="me-0 me-xl-2">
                  <div class="dropdown d-flex flex-row align-items-center">
                    <a target="_blank" href="{{url('/@'.Auth::user()->littlelink_name)}}">
                      <button style="border-bottom-right-radius:0;border-top-right-radius:0;" type="button" class="btn btn-primary btn-sm pe-2">{{__('messages.View Page')}}</button>
                    </a>
                    <button style="border-bottom-left-radius:0;border-top-left-radius:0;" class="btn btn-primary btn-sm dropdown-toggle ms-auto px-1" type="button" id="dropdownMenuButtonSM" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="btn-seg-ico bi bi-share-fill"></i>
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="dropdownMenuButtonSM">
                      <li><h6 class="dropdown-header">{{__('messages.Share your profile:')}}</h6></li>
                      @if(env('SUPPORTED_DOMAINS') !== '' and env('SUPPORTED_DOMAINS') !== null)
                      @php $sDomains = str_replace(' ', '', env('SUPPORTED_DOMAINS')); $sDomains = explode(',', $sDomains); @endphp
                        @foreach ($sDomains as $myvar)
                            <li>
                                <a class="dropdown-item share-button" style="cursor:pointer!important;" data-share="{{'https://'.$myvar.'/@'.Auth::user()->littlelink_name}}">
                                    <i class="bi bi-files"></i> {{ $myvar }}
                                </a>
                            </li>
                        @endforeach         
                      @else
                      <li><a class="dropdown-item share-button" style="cursor:pointer!important;" data-share="{{url('').'/@'.Auth::user()->littlelink_name}}"><i class="bi bi-files"></i> {{ str_replace(['http://', 'https://'], '', url('')) }}                      </a></li>
                      @endif
                      <li><hr class="dropdown-divider"></li>
                      <li><a class="dropdown-item" data-bs-toggle="modal" style="cursor:pointer!important;" data-bs-target="#staticBackdrop"><i class="bi bi-qr-code-scan"></i> {{__('messages.QR Code')}}</a></li>
                    </ul>
                  </div>
                </li>

                {{-- Notification bell removed: its only content was
                     LinkStack-internal notices — "Support LinkStack" /
                     star-on-GitHub / donate links, update nags pointing
                     to linkstack.org, and admin-only security warnings.
                     None of that is relevant to Mail Minted customers,
                     and the marketing links leak the whitelabeled brand.
                     (Suppress the update-check HTTP call to
                     version.linkstack.org via NOTIFY_UPDATES=false in
                     .env — it feeds the removed bell.) --}}

                {{-- <! –– #### begin update detection #### ––> --}}
                @if(env('NOTIFY_UPDATES') == 'true' or env('NOTIFY_UPDATES') === 'major' or env('NOTIFY_UPDATES') === 'all')
              
                              {{-- <! –– Checks if file version.json exists AND if version.json exists on server to continue (without this PHP will throw ErrorException ) ––> --}}
                              @if(file_exists(base_path("version.json")))
              
                                <?php // Requests newest version from server and sets it as variable
              
                                try{
                                $Vgit = external_file_get_contents("https://version.linkstack.org/"); 
              
                             // Requests current version from the local version file and sets it as variable
                                $Vlocal = file_get_contents(base_path("version.json"));
                                }
              
                                catch (Exception $e){
                                $Vgit = "0"; 
                                $Vlocal = "0"; 
                        }
                        ?>
              
                        @if(auth()->user()->role == 'admin')
                        <li class="nav-item dropdown">
                          <a href="#" class="nav-link" id="mail-drop" data-bs-toggle="dropdown"  aria-haspopup="true" aria-expanded="false">
                            <svg class="icon-24" width="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M22 7.92V16.09C22 19.62 19.729 22 16.34 22H7.67C4.28 22 2 19.62 2 16.09V7.92C2 4.38 4.28 2 7.67 2H16.34C19.729 2 22 4.38 22 7.92ZM11.25 9.73V16.08C11.25 16.5 11.59 16.83 12 16.83C12.42 16.83 12.75 16.5 12.75 16.08V9.73L15.22 12.21C15.36 12.35 15.56 12.43 15.75 12.43C15.939 12.43 16.13 12.35 16.28 12.21C16.57 11.92 16.57 11.44 16.28 11.15L12.53 7.38C12.25 7.1 11.75 7.1 11.47 7.38L7.72 11.15C7.43 11.44 7.43 11.92 7.72 12.21C8.02 12.5 8.49 12.5 8.79 12.21L11.25 9.73Z" fill="currentColor"></path>
                                @if($Vgit > $Vlocal or env('JOIN_BETA'))<circle cx="18" cy="17" r="5" @if($Vgit > $Vlocal) fill="tomato" @elseif(env('JOIN_BETA')) fill="orange" @endif stroke="white" stroke-width="2"/>@endif
                            </svg>
                            <span class="bg-primary count-mail"></span>
                          </a>
                          <div class="p-0 sub-drop dropdown-menu dropdown-menu-end" aria-labelledby="mail-drop">
                              <div class="m-0 shadow-none card">
                                @if(env('JOIN_BETA') == true)
                                <div class="py-3 card-header d-flex justify-content-between bg-primary">
                                    <div class="header-title">
                                      <h5 class="mb-0 text-white">{{__('messages.Updater')}} <span style="background-color:orange;" class="badge">{{__('messages.Beta Mode')}}</span></h5>
                                    </div>
                                </div>
                                <div class="p-0 card-body rounded-bottom">
                                  <a href="{{ url('update') }}" class="iq-sub-card">
                                    <div class="d-flex align-items-center">
                                      <table class="m-0 table table-bordered table-sm">
                                        <thead>
                                          <tr>
                                            <th>{{__('messages.Local version')}}</th>
                                            <th>{{__('messages.Latest beta')}}</th>
                                          </tr>
                                        </thead>
                                        <tbody>
                                          <tr>
                                            <td><center><span class="badge rounded-pill bg-primary"><?php  if(file_exists(base_path("vbeta.json"))) {echo file_get_contents(base_path("vbeta.json"));} else {echo "none";}  ?></span></center></td>
                                            <td><center><span class="badge rounded-pill bg-primary"><?php echo external_file_get_contents("https://beta.linkstack.org/vbeta.json"); ?></span></center></td>
                                          </tr>
                                        </tbody>
                                      </table>
                                    </div>
                                    <center><button class="btn btn-primary rounded-pill mt-2">{{__('messages.Run updater')}}</button></center>
                                  </a>
                                </div>
                                @else
                                <div class="py-3 card-header d-flex justify-content-between bg-primary">
                                  <div class="header-title">
                                    <h5 class="mb-0 text-white">{{__('messages.Updater')}}</h5>
                                  </div>
                              </div>
                              <div class="p-0 card-body rounded-bottom">
                                <a @if($Vgit > $Vlocal) href="{{ url('update') }}" @else href="{{url()->current()}}" @endif class="iq-sub-card">
                                  <div class="d-flex align-items-center">
                                    <svg class="icon-32" width="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">                                <path d="M12.0122 14.8299C10.4077 14.8299 9.10986 13.5799 9.10986 12.0099C9.10986 10.4399 10.4077 9.17993 12.0122 9.17993C13.6167 9.17993 14.8839 10.4399 14.8839 12.0099C14.8839 13.5799 13.6167 14.8299 12.0122 14.8299Z" fill="currentColor"></path>                                <path opacity="0.4" d="M21.2301 14.37C21.036 14.07 20.76 13.77 20.4023 13.58C20.1162 13.44 19.9322 13.21 19.7687 12.94C19.2475 12.08 19.5541 10.95 20.4228 10.44C21.4447 9.87 21.7718 8.6 21.179 7.61L20.4943 6.43C19.9118 5.44 18.6344 5.09 17.6226 5.67C16.7233 6.15 15.5685 5.83 15.0473 4.98C14.8838 4.7 14.7918 4.4 14.8122 4.1C14.8429 3.71 14.7203 3.34 14.5363 3.04C14.1582 2.42 13.4735 2 12.7172 2H11.2763C10.5302 2.02 9.84553 2.42 9.4674 3.04C9.27323 3.34 9.16081 3.71 9.18125 4.1C9.20169 4.4 9.10972 4.7 8.9462 4.98C8.425 5.83 7.27019 6.15 6.38109 5.67C5.35913 5.09 4.09191 5.44 3.49917 6.43L2.81446 7.61C2.23194 8.6 2.55897 9.87 3.57071 10.44C4.43937 10.95 4.74596 12.08 4.23498 12.94C4.06125 13.21 3.87729 13.44 3.59115 13.58C3.24368 13.77 2.93709 14.07 2.77358 14.37C2.39546 14.99 2.4159 15.77 2.79402 16.42L3.49917 17.62C3.87729 18.26 4.58245 18.66 5.31825 18.66C5.66572 18.66 6.0745 18.56 6.40153 18.36C6.65702 18.19 6.96361 18.13 7.30085 18.13C8.31259 18.13 9.16081 18.96 9.18125 19.95C9.18125 21.1 10.1215 22 11.3069 22H12.6968C13.872 22 14.8122 21.1 14.8122 19.95C14.8429 18.96 15.6911 18.13 16.7029 18.13C17.0299 18.13 17.3365 18.19 17.6022 18.36C17.9292 18.56 18.3278 18.66 18.6855 18.66C19.411 18.66 20.1162 18.26 20.4943 17.62L21.2097 16.42C21.5776 15.75 21.6083 14.99 21.2301 14.37Z" fill="currentColor"></path>                                </svg>                            
                                      <div class="ms-3 w-100">
                                        <h6 class="mb-0 ">@if($Vgit > $Vlocal) {{__('messages.Update available')}} @else {{__('messages.Up to date')}} @endif</h6>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <p class="mb-0"><i>@if($Vgit > $Vlocal) {{__('messages.Run updater')}} @else {{__('messages.Check again')}} @endif</i></p>
                                            <small class="float-end font-size-12">v{{$Vlocal}}</small>
                                        </div>
                                      </div>
                                  </div>
                                </a>
                              </div>
                                @endif
                              </div>
                          </div>
                        </li>
                        @endif
                      @endif
                @endif
                {{-- <! –– #### end update detection #### ––> --}}

                {{-- Static identity chip (was a dropdown). Its items moved
                     to the sidebar to mirror Mail Minted: Account + Sign out
                     are sidebar items, and Styling is now a sidebar item too. --}}
                <li class="nav-item">
                  <span class="py-0 nav-link d-flex align-items-center">
					@if(file_exists(base_path(findAvatar(Auth::user()->id))))
					<img src="{{ url(findAvatar(Auth::user()->id)) }}" alt="User-Profile" class="img-fluid avatar avatar-40 avatar-rounded" style="object-fit:cover;">
          @elseif(file_exists(base_path("assets/linkstack/images/").findFile('avatar')))
          <img src="{{ url("assets/linkstack/images/")."/".findFile('avatar') }}" alt="User-Profile" class="img logo" style="width:auto;height:30px;">
					@else
					<img src="{{ asset('assets/linkstack/images/logo.svg') }}" alt="User-Profile" class="img-fluid avatar avatar-40 avatar-rounded">
					@endif
                    <div class="caption ms-3 d-none d-md-block ">
                        <h6 class="mb-0 caption-title">{{Auth::user()->name}}</h6>
                        <p class="mb-0 caption-sub-title">
                          @if(Auth::user()->role == "admin")
                          {{__('messages.Administrator')}}
                          @elseif(Auth::user()->role == "vip")
                          {{__('messages.Verified user')}}
                          @else
                          {{__('messages.User')}}
                          @endif
                        </p>
                    </div>
                  </span>
                </li>
              </ul>
            </div>
          </div>
        </nav>          <!-- Nav Header Component Start -->
        <style>.header-block{background-color:var(--bs-primary);border-bottom-left-radius:1rem;border-bottom-right-radius:1rem;}</style>
          <div class="iq-navbar-header header-block mb-2" style="height: 205px;">
              <div class="container-fluid iq-container">
                  <div class="row">
                      <div class="col-md-12">
                          <div style="z-index:5;position:relative;" class="flex-wrap d-flex justify-content-between align-items-center">
                              <div>
                                @if(!isset($usrhandl))
                                  <h1>👋 {{__('messages.Hi')}}, {{__('messages.stranger')}}</h1>
                                @else
                                  <h1>👋 {{__('messages.Hi')}}, {{'@'.$usrhandl}}</h1>
                                @endif

                                  <h5>{{__('messages.welcome', ['appName' => config('app.name')])}}</h5>
                              </div>
                              <div>
                                @if(!isset($usrhandl))
                                  <a href="{{ url('/studio/page') }}" class="btn btn-link btn-soft-light">
                                    <i style="top:3px;position:relative;font-size:2.5vh;" class="bi bi-at"></i>
                                    {{__('messages.Set a handle')}}
                                  </a>
                                  @endif
                              </div>
                          </div>
                      </div>
                  </div>
              </div>
              <div style="z-index:0!important;" class="iq-header-img">
                @php if(file_exists(base_path("assets/dashboard-themes/header.png"))){$headerImage = asset('assets/dashboard-themes/header.png');}else{$headerImage = asset('assets/images/dashboard/top-header-overlay.png');} @endphp
                  <img src="{{$headerImage}}" draggable="false" alt="header" class="img-fluid w-100 h-100 animated-scaleX">
                  <img src="{{$headerImage}}" draggable="false" alt="header" class="theme-color-purple-img img-fluid w-100 h-100 animated-scaleX">
                  <img src="{{$headerImage}}" draggable="false" alt="header" class="theme-color-blue-img img-fluid w-100 h-100 animated-scaleX">
                  <img src="{{$headerImage}}" draggable="false" alt="header" class="theme-color-green-img img-fluid w-100 h-100 animated-scaleX">
                  <img src="{{$headerImage}}" draggable="false" alt="header" class="theme-color-yellow-img img-fluid w-100 h-100 animated-scaleX">
                  <img src="{{$headerImage}}" draggable="false" alt="header" class="theme-color-pink-img img-fluid w-100 h-100 animated-scaleX">
              </div>
          </div>          <!-- Nav Header Component End -->
        <!--Nav End-->

		@yield('content')

      <!-- Footer Section Start -->
      <footer class="footer">
          <div class="footer-body">
              <ul class="left-panel list-inline mb-0 p-0">
                @if(env('DISPLAY_FOOTER') === true)
                  @if(env('DISPLAY_FOOTER_HOME') === true)<li class="list-inline-item"><a class="list-inline-item" href="@if(str_replace('"', "", EnvEditor::getKey('HOME_FOOTER_LINK')) === "" ){{ url('') }}@else{{ str_replace('"', "", EnvEditor::getKey('HOME_FOOTER_LINK')) }}@endif">{{footer('Home')}}</a></li>@endif
                  @if(env('DISPLAY_FOOTER_TERMS') === true)<li class="list-inline-item"><a class="list-inline-item" href="{{ url('') }}/pages/{{ strtolower(footer('Terms')) }}">{{footer('Terms')}}</a></li>@endif
                  @if(env('DISPLAY_FOOTER_PRIVACY') === true)<li class="list-inline-item"><a class="list-inline-item" href="{{ url('') }}/pages/{{ strtolower(footer('Privacy')) }}">{{footer('Privacy')}}</a></li>@endif
                  @if(env('DISPLAY_FOOTER_CONTACT') === true)<li class="list-inline-item"><a class="list-inline-item" href="{{ url('') }}/pages/{{ strtolower(footer('Contact')) }}">{{footer('Contact')}}</a></li>@endif
                @endif
              </ul>
              <div class="right-panel">
                {{__('messages.Copyright')}} &copy; @php echo date('Y'); @endphp {{ config('app.name') }}
                @if(env('DISPLAY_CREDIT_FOOTER') === true)
                  <span class="">
                    - {{__('messages.Made with')}} 
                      <svg class="icon-15" width="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                          <path fill-rule="evenodd" clip-rule="evenodd" d="M15.85 2.50065C16.481 2.50065 17.111 2.58965 17.71 2.79065C21.401 3.99065 22.731 8.04065 21.62 11.5806C20.99 13.3896 19.96 15.0406 18.611 16.3896C16.68 18.2596 14.561 19.9196 12.28 21.3496L12.03 21.5006L11.77 21.3396C9.48102 19.9196 7.35002 18.2596 5.40102 16.3796C4.06102 15.0306 3.03002 13.3896 2.39002 11.5806C1.26002 8.04065 2.59002 3.99065 6.32102 2.76965C6.61102 2.66965 6.91002 2.59965 7.21002 2.56065H7.33002C7.61102 2.51965 7.89002 2.50065 8.17002 2.50065H8.28002C8.91002 2.51965 9.52002 2.62965 10.111 2.83065H10.17C10.21 2.84965 10.24 2.87065 10.26 2.88965C10.481 2.96065 10.69 3.04065 10.89 3.15065L11.27 3.32065C11.3618 3.36962 11.4649 3.44445 11.554 3.50912C11.6104 3.55009 11.6612 3.58699 11.7 3.61065C11.7163 3.62028 11.7329 3.62996 11.7496 3.63972C11.8354 3.68977 11.9247 3.74191 12 3.79965C13.111 2.95065 14.46 2.49065 15.85 2.50065ZM18.51 9.70065C18.92 9.68965 19.27 9.36065 19.3 8.93965V8.82065C19.33 7.41965 18.481 6.15065 17.19 5.66065C16.78 5.51965 16.33 5.74065 16.18 6.16065C16.04 6.58065 16.26 7.04065 16.68 7.18965C17.321 7.42965 17.75 8.06065 17.75 8.75965V8.79065C17.731 9.01965 17.8 9.24065 17.94 9.41065C18.08 9.58065 18.29 9.67965 18.51 9.70065Z" fill="currentColor"></path>
                      </svg>
                  </span> {{__('messages.by')}} <a href="https://linkstack.org/" target="_blank">LinkStack</a>.
                @endif
              </div>
          </div>
      </footer>
      <!-- Footer Section End -->    </main>


    {{-- Layout wrapper close. The removed Styling offcanvas block carried
         a stray closing </div> here (upstream template quirk — it balanced
         an unclosed wrapper opened earlier). Kept on its own so removing
         the offcanvas didn't unbalance the page. --}}
    </div>

      <div class="modal fade" id="staticBackdrop" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
      <div class="modal-dialog">
          <div class="modal-content">
              <div class="modal-header">
                  <h5 class="modal-title" id="staticBackdropLabel">{{__('messages.Scan QR Code')}}</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              @php
              try {
                $redirectURL = url('').'/'.'u/'.Auth::user()->id;

                $argValues = config('advanced-config.qr_code_gradient') ?? [0, 0, 0, 0, 0, 0, 'diagonal'];
                list($arg1, $arg2, $arg3, $arg4, $arg5, $arg6, $arg7) = $argValues;

                if (extension_loaded('imagick')) {
                  $imgSrc = QrCode::format('png')->gradient($arg1, $arg2, $arg3, $arg4, $arg5, $arg6, $arg7)->eye('circle')->style('round')->size(1000)->generate($redirectURL);
                  $imgSrc = base64_encode($imgSrc);
                  $imgSrc = 'data:image/png;base64,' . $imgSrc;
                  $imgType = 'png';
                } else {
                  $imgSrc = QrCode::gradient($arg1, $arg2, $arg3, $arg4, $arg5, $arg6, $arg7)->eye('circle')->style('round')->size(1000)->generate($redirectURL);
                  $imgSrc = base64_encode($imgSrc);
                  $imgSrc = 'data:image/svg+xml;base64,' . $imgSrc;
                  $imgType = 'svg';
                }

              } catch(exception $e) {
                $imgSrc = url('/assets/linkstack/images/themes/no-preview.png');
                $imgType = NULL;
              }
              @endphp
              <div class="modal-body">
                <div class="bd-example">
                  <img id="generatedImage" draggable="false" src="@php if(isset($imgSrc)){echo $imgSrc;} @endphp" style="width:100%;height:auto;" class="bd-placeholder-img img-thumbnail">
              </div>
              </div>
              <div class="modal-footer">
                @if($imgType == 'png')
                  <button type="button" class="btn btn-info" id="downloadButton">{{__('messages.Download')}}</button>
                @endif
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{__('messages.Close')}}</button>
              </div>
          </div>
      </div>
      </div>

      <script nonce="{{ csp_nonce() }}">
        document.addEventListener("DOMContentLoaded", function() {
            var downloadButton = document.getElementById("downloadButton");
            var generatedImage = document.getElementById("generatedImage");

            // Bail if this page doesn't have the QR-code download widget
            // (most pages). Without this guard the missing element
            // throws "Cannot read properties of null" on every studio
            // page that ISN'T /studio/qr — which cascades and can
            // mask other JS that runs later in the load order.
            if (!downloadButton || !generatedImage) return;

            downloadButton.addEventListener("click", function() {
                var format = generatedImage.getAttribute("data-format") || "png";
                var downloadLink = document.createElement("a");
                downloadLink.href = generatedImage.src;
                downloadLink.download = "generated_image." + format;
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);
            });
        });
        </script>

    <!-- Library Bundle Script -->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/core/libs.min.js')}}"></script>
    
    <!-- External Library Bundle Script -->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/core/external.min.js')}}"></script>
    
    <!-- Widgetchart Script -->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/charts/widgetcharts.js')}}"></script>
    
    <!-- mapchart Script -->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/charts/vectore-chart.js')}}"></script>
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/charts/dashboard.js')}}" ></script>
    
    <!-- fslightbox Script -->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/plugins/fslightbox.js')}}"></script>
    
    <!-- Settings Script -->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/plugins/setting.js')}}"></script>
    
    <!-- Slider-tab Script -->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/plugins/slider-tabs.js')}}"></script>
    
    <!-- Form Wizard Script -->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/plugins/form-wizard.js')}}"></script>
    
    <!-- AOS Animation Plugin-->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/vendor/aos/dist/aos.js')}}"></script>
    
    <!-- App Script -->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/hope-ui.js')}}" defer></script>
    
    <!-- Flatpickr Script -->
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/vendor/flatpickr/dist/flatpickr.min.js')}}"></script>
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/plugins/flatpickr.js')}}" defer></script>
    
    <script nonce="{{ csp_nonce() }}" src="{{asset('assets/js/plugins/prism.mini.js')}}"></script>

    <!-- Share Button -->
    <script nonce="{{ csp_nonce() }}">
      // Get a reference to all buttons with the class "share-button"
      const shareButtons = document.querySelectorAll('.share-button');
      
      // Add a click event listener to each button
      shareButtons.forEach(button => {
        button.addEventListener('click', () => {
          // Get the value to share/copy from the "data-share" attribute
          const valueToShare = button.dataset.share;
      
          // Check if the Web Share API is supported
          if (navigator.share) {
            // Call the Web Share API to open the native share dialog
            navigator.share({
              title: '{{__("messages.Share your profile")}}',
              text: valueToShare,
              url: valueToShare,
            })
            .catch(err => console.error('{{__("messages.Error sharing:")}}', err));
          } else {
            // If the Web Share API is not supported, copy the value to the clipboard
            navigator.clipboard.writeText(valueToShare)
            .then(() => {
              // If copying was successful, alert the user
              alert('{{__("messages.Text copied to clipboard!")}}');
            })
            .catch(err => {
              // If copying failed, alert the user
              alert('{{__("messages.Error copying text:")}}', err);
            });
          }
        });
      });
      </script>

<script nonce="{{ csp_nonce() }}" src="{{ asset('assets/js/popper.js') }}"></script>
<script nonce="{{ csp_nonce() }}" src="{{ asset('assets/js/bootstrap.min.js') }}"></script>
<script nonce="{{ csp_nonce() }}" src="{{ asset('assets/js/Sortable.min.js') }}"></script>
<script nonce="{{ csp_nonce() }}" src="{{ asset('assets/js/jquery-block-ui.js') }}"></script>
<script nonce="{{ csp_nonce() }}" src="{{ asset('assets/js/main-dashboard.js') }}?v={{ filemtime(public_path('assets/js/main-dashboard.js')) }}"></script>
<script nonce="{{ csp_nonce() }}" src="{{ asset('assets/js/mm-confirm.js') }}?v={{ filemtime(public_path('assets/js/mm-confirm.js')) }}"></script>

<script nonce="{{ csp_nonce() }}">
    // Delegated replacements for inline on*= attributes — a strict
    // script-src CSP blocks inline event handlers, so these live once in
    // the studio layout and cover every studio page (incl. the
    // block-editor iframe, which also extends this layout).
    (function () {
        // Confirm-gated clicks: an element with data-confirm="msg" holds
        // its default action (navigation, submit, or the element's own
        // listeners) behind an INLINE yes/cancel strip — not
        // window.confirm(), which Chrome silently suppresses after a few
        // dismissals, leaving guarded buttons "doing nothing" for the
        // rest of the tab. Capture phase so it runs before the element's
        // own handlers; a one-shot flag lets the confirmed re-click pass.
        document.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
            if (!el) return;
            if (el.dataset.mmConfirmed === '1') {
                delete el.dataset.mmConfirmed;
                return; // confirmed re-fire — let it through
            }
            e.preventDefault();
            e.stopImmediatePropagation();
            if (!window.mmConfirm) { // script failed to load — degrade to native
                if (window.confirm(el.getAttribute('data-confirm'))) {
                    el.dataset.mmConfirmed = '1';
                    el.click();
                }
                return;
            }
            window.mmConfirm(el, el.getAttribute('data-confirm'), function () {
                el.dataset.mmConfirmed = '1';
                el.click();
            });
        }, true);

        // Favicon fallback: <img data-fallback="url"> swaps to the
        // fallback when its src errors. Capture phase because <img> error
        // events don't bubble.
        document.addEventListener('error', function (e) {
            var t = e.target;
            if (t && t.tagName === 'IMG' && t.dataset && t.dataset.fallback && t.getAttribute('src') !== t.dataset.fallback) {
                t.src = t.dataset.fallback;
                t.removeAttribute('data-fallback');
            }
        }, true);
    })();
</script>

@stack('sidebar-scripts')

  </body>
</html>
