<x-error-shell
    code="503"
    :title="__('errors.page_503_title')"
    :body="__('errors.page_503_body')"
    icon='<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>'
>
    <button type="button" onclick="window.location.reload()"
            class="primary">
        {{ __('errors.page_reload') }}
    </button>
</x-error-shell>
