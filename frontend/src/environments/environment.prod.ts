// Az éles környezet (vigadomozi.hu): az API külön aldomainen fut (api.vigadomozi.hu).
// Ugyanazon a domainen futó telepítéshez a "same-origin" konfiguráció (environment.same-origin.ts) használható.
export const environment = {
  production: true,
  apiUrl: 'https://api.vigadomozi.hu/api'
};
