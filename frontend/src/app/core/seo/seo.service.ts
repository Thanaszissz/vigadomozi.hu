import { Inject, Injectable } from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { Meta, Title } from '@angular/platform-browser';
import { environment } from 'src/environments/environment';
import { Movie, Showtime } from '../api/api.service';

export interface SeoData {
  title?: string;
  description?: string;
  image?: string;
  /** noindex: a tranzakciós és admin oldalak ne kerüljenek a keresőkbe */
  noindex?: boolean;
  type?: 'website' | 'video.movie';
}

export const SITE_NAME = 'Vigadó Jegy';
const DEFAULT_TITLE = 'Vigadó Jegy Kisújszállás – műsor, jegyvásárlás online';
const DEFAULT_DESCRIPTION =
  'A kisújszállási Vigadó Jegy műsora, vetítési időpontok, jegyárak és online jegyfoglalás. Foglalj helyet kedvenc filmedre percek alatt!';
const DEFAULT_IMAGE = '/assets/og-default.jpg';

const THEATER = {
  '@type': 'MovieTheater',
  name: 'Vigadó Jegy',
  telephone: '+36-59-328-083',
  email: 'info@vigadojegy.hu',
  address: {
    '@type': 'PostalAddress',
    streetAddress: 'Szabadság tér 7.',
    addressLocality: 'Kisújszállás',
    postalCode: '5310',
    addressCountry: 'HU'
  }
};

@Injectable({ providedIn: 'root' })
export class SeoService {
  private readonly siteUrl = environment.siteUrl.replace(/\/+$/, '');

  constructor(
    private titleService: Title,
    private meta: Meta,
    @Inject(DOCUMENT) private doc: Document
  ) {}

  /** Az oldal fejlécének (title, leírás, Open Graph, Twitter, canonical) beállítása. */
  update(path: string, data: SeoData = {}): void {
    const title = data.title ? `${data.title} | ${SITE_NAME} Kisújszállás` : DEFAULT_TITLE;
    const description = this.truncate(data.description || DEFAULT_DESCRIPTION, 300);
    const url = this.absolute(this.cleanPath(path));
    const image = this.absolute(data.image || DEFAULT_IMAGE);

    this.titleService.setTitle(title);
    this.setName('description', description);
    this.setName('robots', data.noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large');

    this.setProperty('og:site_name', SITE_NAME);
    this.setProperty('og:locale', 'hu_HU');
    this.setProperty('og:type', data.type || 'website');
    this.setProperty('og:title', title);
    this.setProperty('og:description', description);
    this.setProperty('og:url', url);
    this.setProperty('og:image', image);
    this.setProperty('og:image:alt', data.title || SITE_NAME);

    this.setName('twitter:card', 'summary_large_image');
    this.setName('twitter:title', title);
    this.setName('twitter:description', description);
    this.setName('twitter:image', image);

    this.setCanonical(url);
    this.setJsonLd(null);
  }

  /** Filmoldal: egyedi cím, leírás, plakát (Facebook-megosztáshoz is) és a Movie / ScreeningEvent adatok. */
  setMovie(movie: Movie, showtimes: Showtime[] = []): void {
    const rating = this.ratingLabel(movie.rating);
    const parts = [this.sentence(movie.description), movie.duration_min ? `Játékidő: ${movie.duration_min} perc.` : '', rating ? `Korhatár: ${rating}.` : ''];
    const description = parts.filter(Boolean).join(' ');
    const path = `/movies/${movie.id}`;

    this.update(path, {
      title: movie.title,
      description,
      image: movie.poster_path || undefined,
      type: 'video.movie'
    });

    const work: any = {
      '@type': 'Movie',
      name: movie.title,
      description: movie.description || undefined,
      image: movie.poster_path || undefined,
      url: this.absolute(path),
      duration: movie.duration_min ? `PT${movie.duration_min}M` : undefined,
      contentRating: rating || undefined,
      trailer: movie.trailer_youtube_url
        ? { '@type': 'VideoObject', name: `${movie.title} – előzetes`, embedUrl: movie.trailer_youtube_url, description: movie.description || movie.title, thumbnailUrl: movie.poster_path || undefined, uploadDate: movie.created_at || undefined }
        : undefined
    };

    const now = Date.now();
    const events = showtimes
      .filter(s => s.movie_id === movie.id && s.status === 'active' && new Date(s.starts_at).getTime() > now)
      .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime())
      .slice(0, 12)
      .map(s => ({
        '@type': 'ScreeningEvent',
        name: movie.title,
        startDate: new Date(s.starts_at).toISOString(),
        eventStatus: 'https://schema.org/EventScheduled',
        eventAttendanceMode: 'https://schema.org/OfflineEventAttendanceMode',
        location: THEATER,
        workPresented: { '@type': 'Movie', name: movie.title },
        url: this.absolute(`/showtimes/${s.id}/select-seats`),
        image: movie.poster_path || undefined
      }));

    this.setJsonLd({ '@context': 'https://schema.org', '@graph': [work, ...events] });
  }

  private sentence(text?: string): string {
    const trimmed = (text || '').trim();
    return trimmed && !/[.!?…]$/.test(trimmed) ? trimmed + '.' : trimmed;
  }

  private ratingLabel(rating?: string): string {
    if (!rating) return '';
    return rating === 'general' ? 'Korhatár nélkül' : `${rating}+`;
  }

  private cleanPath(path: string): string {
    const clean = (path || '/').split(/[?#]/)[0].replace(/\/+$/, '');
    return clean || '/';
  }

  private absolute(url: string): string {
    return /^https?:\/\//i.test(url) ? url : this.siteUrl + (url.startsWith('/') ? url : '/' + url);
  }

  private truncate(text: string, max: number): string {
    const plain = text.replace(/\s+/g, ' ').trim();
    return plain.length <= max ? plain : plain.slice(0, max - 1).replace(/\s+\S*$/, '') + '…';
  }

  private setName(name: string, content: string): void {
    this.meta.updateTag({ name, content });
  }

  private setProperty(property: string, content: string): void {
    this.meta.updateTag({ property, content }, `property='${property}'`);
  }

  private setCanonical(url: string): void {
    let link = this.doc.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');
    if (!link) {
      link = this.doc.createElement('link');
      link.setAttribute('rel', 'canonical');
      this.doc.head.appendChild(link);
    }
    link.setAttribute('href', url);
  }

  private setJsonLd(data: object | null): void {
    const id = 'seo-jsonld';
    this.doc.getElementById(id)?.remove();
    if (!data) return;

    const script = this.doc.createElement('script');
    script.id = id;
    script.type = 'application/ld+json';
    script.text = JSON.stringify(data);
    this.doc.head.appendChild(script);
  }
}
