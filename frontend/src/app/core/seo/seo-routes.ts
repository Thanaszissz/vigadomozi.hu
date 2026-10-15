import { SeoData } from './seo.service';

/** Az oldalak keresőoptimalizált adatai útvonalanként. A share.php ugyanezeket a szövegeket használja a közösségi megosztásokhoz. */
const PAGES: Record<string, SeoData> = {
  '/': {
    description: 'A kisújszállási Vigadó Jegy műsora, vetítési időpontok, jegyárak és online jegyfoglalás. Foglalj helyet kedvenc filmedre percek alatt!'
  },
  '/movies': {
    title: 'Filmek',
    description: 'Aktuális filmek a kisújszállási Vigadó Jegyen: plakátok, leírások, előzetesek és vetítési időpontok egy helyen.'
  },
  '/schedule': {
    title: 'Műsor és vetítési időpontok',
    description: 'A Vigadó Jegy teljes műsora napokra bontva: mikor és mit játszunk Kisújszálláson. Jegyfoglalás online.'
  },
  '/prices': {
    title: 'Jegyárak',
    description: 'A Vigadó Jegy jegyárai Kisújszálláson: teljes árú, kedvezményes és csoportos jegyek.'
  },
  '/mediaajanlat': {
    title: 'Médiaajánlat',
    description: 'Hirdetési és médiaajánlat a Vigadó Jegy vásznán és felületein, Kisújszálláson.'
  },
  '/hazirend': {
    title: 'Házirend',
    description: 'A Vigadó Jegy házirendje: látogatási szabályok, korhatár-besorolások és tudnivalók a mozilátogatáshoz.'
  },
  '/fizetesi-tajekoztato': {
    title: 'Fizetési tájékoztató',
    description: 'Fizetési tájékoztató a Vigadó Jegy online jegyvásárlásához: elfogadott fizetési módok és a vásárlás menete.'
  },
  '/adatkezelesi-tajekoztato': {
    title: 'Adatkezelési tájékoztató',
    description: 'A Vigadó Jegy adatkezelési tájékoztatója: hogyan kezeljük a jegyfoglalás és vásárlás során megadott adataidat.'
  }
};

/** Ezek az oldalak személyes vagy tranzakciós tartalmat mutatnak, ezért nem kerülhetnek a keresőkbe. */
const NOINDEX_PREFIXES = ['/admin', '/checkout', '/hold-summary', '/success', '/showtimes'];

export function seoForPath(path: string): SeoData {
  const clean = (path || '/').split(/[?#]/)[0].replace(/\/+$/, '') || '/';

  if (NOINDEX_PREFIXES.some(prefix => clean === prefix || clean.startsWith(prefix + '/'))) {
    return { title: 'Foglalás', noindex: true };
  }

  if (/^\/movies\/\d+$/.test(clean)) {
    return { title: 'Film' };
  }

  return PAGES[clean] ?? {};
}
