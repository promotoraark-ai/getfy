/** Pathname sem query/hash — para item ativo do menu após navegação Inertia com ?params */
export function normalizeNavPath(url) {
    if (!url || typeof url !== 'string') {
        return '/';
    }
    return url.split('?')[0].split('#')[0] || '/';
}

export function isNavItemActive(pageUrl, href) {
    const path = normalizeNavPath(pageUrl);
    if (href === '/dashboard') {
        return path === '/dashboard' || path === '/';
    }
    if (href === '/parceiro') {
        return path === '/parceiro';
    }
    return path === href || path.startsWith(`${href}/`);
}
