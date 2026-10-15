// Az éles környezet (vigadojegy.hu): az API külön aldomainen fut (api.vigadojegy.hu).
// Ugyanazon a domainen futó telepítéshez a "same-origin" konfiguráció (environment.same-origin.ts) használható.
export const environment = {
  production: true,
  apiUrl: 'https://api.vigadojegy.hu/api',
  siteUrl: 'https://vigadojegy.hu'
};
