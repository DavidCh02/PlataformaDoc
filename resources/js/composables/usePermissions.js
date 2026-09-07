import { usePage } from '@inertiajs/vue3';

export function usePermissions() {
    const page = usePage();

    const can = (permission) => {
        const granted = page.props.auth?.can;
        return Array.isArray(granted) ? granted.includes(permission) : false;
    };

    return { can };
}