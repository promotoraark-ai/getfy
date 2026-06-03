/**
 * Helpers para saber quais plataformas de pixel estão ativas no checkout.
 */

function platformEntries(pixels, key) {
    const block = pixels?.[key];
    if (!block?.enabled) {
        return [];
    }
    if (Array.isArray(block.entries) && block.entries.length) {
        return block.entries;
    }
    return [];
}

export function pixelsNeedMeta(pixels) {
    return pixelsHaveMetaEntries(pixels);
}

/** true quando há pixel_id Meta resolvido (entries), independente de enabled. */
export function pixelsHaveMetaEntries(pixels) {
    const block = pixels?.meta;
    if (!block) {
        return false;
    }
    if (Array.isArray(block.entries) && block.entries.length) {
        return block.entries.some((e) => e && String(e.pixel_id ?? '').trim() !== '');
    }
    return String(block.pixel_id ?? '').trim() !== '';
}

export function pixelsHaveGoogleAdsEntries(pixels) {
    const block = pixels?.google_ads;
    if (!block) {
        return false;
    }
    if (Array.isArray(block.entries) && block.entries.length) {
        return block.entries.some((e) => e && String(e.conversion_id ?? '').trim() !== '');
    }
    return block.enabled && String(block.conversion_id ?? '').trim() !== '';
}

export function pixelsHaveGaEntries(pixels) {
    const block = pixels?.google_analytics;
    if (!block) {
        return false;
    }
    if (Array.isArray(block.entries) && block.entries.length) {
        return block.entries.some((e) => e && String(e.measurement_id ?? '').trim() !== '');
    }
    return block.enabled && String(block.measurement_id ?? '').trim() !== '';
}

export function pixelsHaveGoogleEntries(pixels) {
    return pixelsHaveGoogleAdsEntries(pixels) || pixelsHaveGaEntries(pixels);
}

export function pixelsHaveTiktokEntries(pixels) {
    const block = pixels?.tiktok;
    if (!block) {
        return false;
    }
    if (Array.isArray(block.entries) && block.entries.length) {
        return block.entries.some((e) => e && String(e.pixel_id ?? '').trim() !== '');
    }
    return String(block.pixel_id ?? '').trim() !== '';
}

export function pixelsNeedGoogle(pixels) {
    return pixelsHaveGoogleEntries(pixels);
}

export function pixelsNeedTiktok(pixels) {
    return pixelsHaveTiktokEntries(pixels);
}

/** Meta, Google gtag ou TikTok precisam de SDK browser carregado antes de eventos. */
export function pixelsNeedAnyBrowserSdk(pixels) {
    return (
        pixelsHaveMetaEntries(pixels)
        || pixelsHaveGoogleEntries(pixels)
        || pixelsHaveTiktokEntries(pixels)
    );
}

export function allPixelEntries(pixels) {
    const entries = [];
    const blocks = [
        ['meta', (e) => String(e?.pixel_id ?? '').trim() !== ''],
        ['tiktok', (e) => String(e?.pixel_id ?? '').trim() !== ''],
        ['google_ads', (e) => String(e?.conversion_id ?? '').trim() !== ''],
        ['google_analytics', (e) => String(e?.measurement_id ?? '').trim() !== ''],
    ];
    for (const [key, isValid] of blocks) {
        const block = pixels?.[key];
        if (!block) continue;
        if (Array.isArray(block.entries) && block.entries.length) {
            entries.push(...block.entries.filter((e) => e && isValid(e)));
        } else if (block.enabled) {
            entries.push(...platformEntries(pixels, key));
        }
    }
    return entries;
}

/** true se algum pixel dispara Purchase ao gerar PIX (padrão: true). */
export function shouldFirePurchaseOnPixGeneration(pixels) {
    return allPixelEntries(pixels).some((entry) => entry?.fire_purchase_on_pix !== false);
}

/** true se algum pixel dispara Purchase ao gerar boleto (padrão: true). */
export function shouldFirePurchaseOnBoletoGeneration(pixels) {
    return allPixelEntries(pixels).some((entry) => entry?.fire_purchase_on_boleto !== false);
}

export function isValidGtmContainerId(id) {
    if (typeof id !== 'string') {
        return false;
    }
    const trimmed = id.trim().toUpperCase();
    return /^GTM-[A-Z0-9]+$/.test(trimmed);
}

export function getGtmContainerId(pixels) {
    const block = pixels?.gtm;
    if (!block?.enabled) {
        return '';
    }
    const id = String(block.container_id ?? '').trim().toUpperCase();
    return isValidGtmContainerId(id) ? id : '';
}
