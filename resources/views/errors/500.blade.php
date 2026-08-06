<x-error-shell
    code="500"
    :title="__('errors.page_500_title')"
    :body="__('errors.page_500_body')"
    icon='<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9.303-3.376c.866 1.5-.217 3.374-1.948 3.374H4.645c-1.73 0-2.813-1.874-1.948-3.374L9.4 3.378c.866-1.5 3.032-1.5 3.898 0l6.85 11.872ZM12 17.25h.007v.008H12v-.008Z" /></svg>'
>
    <button type="button" onclick="window.location.reload()"
            class="primary">
        {{ __('errors.page_reload') }}
    </button>
</x-error-shell>
