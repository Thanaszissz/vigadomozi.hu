/** HTML-escape: kézzel összeállított HTML-be (nyomtatási ablakok) szánt, felhasználótól származó szövegekhez. */
export function escapeHtml(value: unknown): string {
  return String(value ?? '').replace(/[&<>"'`]/g, ch => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
    '`': '&#96;'
  }[ch] as string));
}

/** YouTube-azonosító kinyerése; csak a szabályos 11 karakteres azonosító fogadható el, így nem lehet tetszőleges URL-t beágyazni. */
export function youtubeId(url: string | null | undefined): string | null {
  if (!url) return null;
  try {
    const u = new URL(url);
    const host = u.hostname.replace(/^www\./, '');
    let id: string | null = null;
    if (host === 'youtu.be') id = u.pathname.slice(1).split('/')[0];
    else if (host === 'youtube.com' || host === 'm.youtube.com') {
      id = u.pathname.startsWith('/embed/') ? u.pathname.split('/')[2] : u.searchParams.get('v');
    }
    return id && /^[\w-]{11}$/.test(id) ? id : null;
  } catch {
    return null;
  }
}
