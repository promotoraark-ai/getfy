/** Carrega Vidstack sob demanda (aulas da área de membros) para não inflar o bundle inicial. */
let loadPromise = null;

export function loadVidstack() {
    if (!loadPromise) {
        loadPromise = Promise.all([
            import('vidstack/player/styles/default/theme.css'),
            import('vidstack/player/styles/default/layouts/audio.css'),
            import('vidstack/player/styles/default/layouts/video.css'),
            import('vidstack/player'),
            import('vidstack/player/layouts'),
            import('vidstack/player/ui'),
        ]).then(() => true);
    }

    return loadPromise;
}
