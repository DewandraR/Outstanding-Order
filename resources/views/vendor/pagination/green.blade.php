@if ($paginator->hasPages())
<nav role="navigation" aria-label="Pagination" class="mt-3">
    <ul class="pagination pagination-sm justify-content-center my-0">

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
        <li class="page-item disabled">
            <span class="page-link bg-gray-100 border-gray-200 text-muted">« Prev</span>
        </li>
        @else
        <li class="page-item">
            <a class="page-link border-green-300 text-green-700 hover-bg"
                href="{{ $paginator->previousPageUrl() }}" rel="prev">« Prev</a>
        </li>
        @endif

        {{-- Numbers --}}
        @foreach ($elements as $element)
        @if (is_string($element))
        <li class="page-item disabled">
            <span class="page-link bg-gray-100 border-gray-200 text-muted">{{ $element }}</span>
        </li>
        @endif

        @if (is_array($element))
        @foreach ($element as $page => $url)
        @if ($page == $paginator->currentPage())
        <li class="page-item active" aria-current="page">
            <span class="page-link bg-green-600 border-green-600 text-white shadow-sm">{{ $page }}</span>
        </li>
        @else
        <li class="page-item">
            <a class="page-link border-green-300 text-green-700 hover-bg"
                href="{{ $url }}">{{ $page }}</a>
        </li>
        @endif
        @endforeach
        @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
        <li class="page-item">
            <a class="page-link border-green-300 text-green-700 hover-bg"
                href="{{ $paginator->nextPageUrl() }}" rel="next">Next »</a>
        </li>
        @else
        <li class="page-item disabled">
            <span class="page-link bg-gray-100 border-gray-200 text-muted">Next »</span>
        </li>
        @endif
    </ul>
</nav>
@endif