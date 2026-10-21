import { Injectable } from '@angular/core';
import { environment } from '../../environments/environment';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface Movie {
  id: number;
  title: string;
  description: string;
  duration_minutes: number;
  poster_url?: string;
  rating?: 'general' | '6' | '12' | '16' | '18';
  genre?: string;
  film_info?: string | null;
  badges?: string[] | null;
  duration_min?: number;
  poster_path?: string;
  trailer_youtube_url?: string;
}

export interface CatalogImportShowtimeRow {
  index: number | null;
  id?: number | null;
  starts_at?: string;
  auditorium?: string;
  status: 'created' | 'skipped' | 'failed';
  message?: string;
}

export interface CatalogImportMovieRow {
  index: number;
  title: string;
  status: 'created' | 'updated' | 'skipped' | 'failed';
  id: number | null;
  message?: string;
  errors: string[];
  poster: { status: 'ok' | 'converted' | 'failed'; message?: string; url?: string; width?: number; height?: number; bytes?: number } | null;
  showtimes: CatalogImportShowtimeRow[];
}

export type CatalogImportType = 'movie' | 'show';

export interface CatalogImportResult {
  dry_run: boolean;
  type: CatalogImportType;
  summary: {
    items_created: number; items_updated: number; items_failed: number;
    posters_converted: number; poster_warnings: number;
    showtimes_created: number; showtimes_skipped: number; showtimes_failed: number;
  };
  items: CatalogImportMovieRow[];
}

export interface TheaterShow {
  id: number;
  title: string;
  category?: string | null;
  performer?: string | null;
  venue?: string | null;
  show_info?: string | null;
  ticket_price?: number | null;
  badges?: string[] | null;
  description: string;
  duration_minutes: number;
  poster_url?: string;
  rating?: 'general' | '6' | '12' | '16' | '18';
  duration_min?: number;
  poster_path?: string;
  trailer_youtube_url?: string;
}

export type PaymentMethod = 'cash' | 'card';

export interface Ticket {
  id: number;
  ticket_year: number;
  ticket_seq: number;
  ticket_number: string;
  reservation_id: number | null;
  showtime_id: number | null;
  movie_title: string;
  starts_at: string;
  auditorium_name: string | null;
  seat_key: string;
  section?: string | null;
  row_label: string;
  seat_number: number | null;
  price_amount: number;
  currency: string;
  payment_method: string;
  channel: string;
  category?: TicketCategory;
  sold_by_name: string | null;
  sold_at: string;
}

export type TicketCategory = 'standard' | 'staff' | 'experience_card' | 'service';

export const CATEGORY_LABELS: Record<TicketCategory, string> = {
  standard: 'Normál jegy',
  staff: 'Dolgozó jegy',
  experience_card: 'Élménykártya',
  service: 'Egyéb szolgáltatás'
};

export function categoryLabel(category?: string | null): string {
  return CATEGORY_LABELS[(category ?? 'standard') as TicketCategory] ?? 'Normál jegy';
}

export interface TicketPage {
  year: number;
  summary: {
    tickets: number;
    amount: number;
    by_method: Array<{ payment_method: string; tickets: number; amount: number }>;
    by_category?: Array<{ category: TicketCategory; tickets: number; amount: number }>;
  };
  tickets: {
    data: Ticket[];
    current_page: number;
    last_page: number;
    total: number;
  };
}

export interface MethodTotals {
  payment_method: string;
  tickets: number;
  amount: number;
}

export interface SellerTotals {
  tickets: number;
  amount: number;
  by_method: MethodTotals[];
}

export interface DailyClose {
  date: string;
  generated_at: string;
  scope?: 'own' | 'all';
  totals: SellerTotals;
  sellers: Array<{
    user_id: number | null;
    user_name: string;
    totals: SellerTotals;
    tickets: Ticket[];
  }>;
}

export interface ShowtimeDayTotals {
  tickets: number;
  amount: number;
  cash: number;
  card: number;
  experience_card: number;
  online: number;
  staff: number;
  experience_tickets: number;
}

export interface ShowtimeDay {
  date: string;
  totals: ShowtimeDayTotals;
  shows: Array<ShowtimeDayTotals & {
    showtime_id: number | null;
    movie_title: string;
    starts_at: string;
    auditorium_name: string | null;
  }>;
  tickets: Ticket[];
}

export interface SalesLogEntry {
  id: number;
  occurred_at: string;
  user_id: number | null;
  user_name: string | null;
  channel: string;
  reservation_id: number | null;
  movie_title: string | null;
  starts_at: string | null;
  ticket_count: number;
  total_amount: number;
  payment_method: string;
  ticket_numbers: string;
  ip_address: string | null;
}

export interface ActivityLogEntry {
  id: number;
  occurred_at: string;
  user_id: number | null;
  user_name: string | null;
  user_email: string | null;
  category: string;
  action: string;
  description: string;
  subject_type: string | null;
  subject_id: number | null;
  subject_label: string | null;
  properties: any;
  ip_address: string | null;
  user_agent: string | null;
}

export interface ActivityLogPage {
  total: number;
  logs: {
    data: ActivityLogEntry[];
    current_page: number;
    last_page: number;
  };
}

export interface ActivityMeta {
  categories: Array<{ key: string; label: string }>;
  actions: Array<{ key: string; category: string; label: string }>;
  users: Array<{ id: number; name: string }>;
}

export interface EmailTemplate {
  id: number;
  key: string;
  name: string;
  description: string | null;
  subject: string;
  body: string;
  is_active: boolean;
  updated_at: string;
}

export interface EmailTemplatesResponse {
  templates: EmailTemplate[];
  placeholders: Array<{ key: string; description: string }>;
  mail: { mailer: string; from: string; delivers: boolean };
}

export interface TicketNumberingState {
  prefix: string;
  last_number: number;
  last_number_formatted: string | null;
  next_number: string;
  min_counter: number;
  max_counter: number;
  last_ticket: { number: string; sold_at: string; prefix: string; seq: number } | null;
  previous_prefix_last: { number: string; prefix: string; seq: number } | null;
  counters: Array<{ prefix: string; last_number: number; tickets: number; last_sold_at: string | null; is_current: boolean }>;
  year_notice: boolean;
  current_year: number;
  reset_phrase: string;
}

export interface TicketNumberingPlan {
  token: string;
  expires_in: number;
  action: 'prefix' | 'counter';
  phrase: string;
  summary: string[];
  warnings: string[];
}

export interface NavSubmissionItem {
  id: number;
  applicable_date: string;
  environment: string;
  operation: 'create' | 'modify';
  trigger: 'manual' | 'auto';
  status: 'PENDING' | 'SENT' | 'FAILED' | 'REPLACED';
  nav_id: string | null;
  serial_number: string;
  sale_count: number;
  total_amount: number;
  sent_by: string | null;
  error_message: string | null;
  created_at: string;
}

export interface NavState {
  enabled: boolean;
  configured: boolean;
  active: boolean;
  environment: string;
  tax_number: string;
  login: string;
  has_password: boolean;
  has_signature_key: boolean;
  signature_key_hint: string | null;
  software_name: string;
  vat_category: string;
  vat_options: string[];
  base_url: string;
  schedule: {
    auto_enabled: boolean;
    auto_time: string;
    catchup_days: number;
    next_run: string | null;
    last_run: string | null;
    last_status: { at: string; ok: boolean; sent: number; failed: number; message: string } | null;
  };
  unsent_days: string[];
  today: string;
  yesterday: string;
  submissions: NavSubmissionItem[];
}

export interface NavDayData {
  date: string;
  count: number;
  total: number;
  serial: string;
  last_serial: string;
  currency: string;
  vat: Array<{ name: string; sale: number; modifying: number }>;
  by_method: Array<{ method: string; tickets: number; amount: number }>;
}

export interface NavPreview {
  data: NavDayData;
  operation: 'create' | 'modify';
  blocker: string | null;
  sent: { nav_id: string; sent_at: string; sale_count: number; total_amount: number } | null;
  xml: string | null;
  is_today: boolean;
}

export interface NavSendPlan {
  token: string;
  expires_in: number;
  phrase: string;
  environment: string;
  operation: string;
  data: NavDayData;
  warnings: string[];
  summary: string[];
}

export interface NavTestResult {
  ok: boolean;
  steps: Array<{ name: string; ok: boolean; message: string }>;
  vat_categories: string[];
  software_registered: boolean | null;
}

export interface SimplePaySettings {
  enabled: boolean;
  ready: boolean;
  environment: string;
  merchant: string;
  has_secret: boolean;
  secret_hint: string | null;
  language: string;
  languages: string[];
  timeout_minutes: number;
  site_url: string;
  urls: { ipn: string; return: string; api: string };
  recent_payments: Array<{
    id: number; reservation_id: number; order_ref: string; transaction_id: string | null;
    status: string; amount: number; environment: string | null; created_at: string; completed_at: string | null;
  }>;
}

export interface SimplePayTestResult {
  ok: boolean;
  message: string;
  http_status: number | null;
  error_codes: string[];
  signature_valid: boolean | null;
}

export interface ExtraService {
  id: number;
  name: string;
  price: number;
  kind?: 'service' | 'staff_ticket';
  sort_order: number;
}

export interface SzamlazzSettings {
  enabled: boolean;
  ready: boolean;
  has_key: boolean;
  key_hint: string | null;
  vat_rate: string;
  vat_rates: string[];
  e_invoice: boolean;
  send_email: boolean;
  prefix: string;
  email_subject: string;
  email_text: string;
  recent_invoices: Array<{
    id: number;
    reservation_id: number;
    invoice_number: string;
    buyer_name: string;
    gross_amount: number;
    vat_rate: string;
    issued_by_name: string | null;
    issued_at: string;
  }>;
}

export interface InvoiceBuyer {
  name: string;
  zip: string;
  city: string;
  address: string;
  tax_number?: string | null;
  email?: string | null;
}

export interface PosInvoice {
  id: number;
  reservation_id: number;
  invoice_number: string;
  buyer_name: string;
  gross_amount: number;
  vat_rate: string;
  has_pdf: boolean;
}

export interface BackupStats {
  tickets: number;
  reservations: number;
  users: number;
  showtimes: number;
  movies: number;
}

export interface BackupItem {
  id: string;
  type: 'manual' | 'auto' | 'prerestore' | 'upload';
  created_at: string;
  created_by: string | null;
  size: number;
  code: string;
  total_rows: number;
  stats: BackupStats;
  valid?: boolean;
  error?: string;
}

export interface BackupSchedule {
  enabled: boolean;
  frequency: string;
  time: string;
  weekday: number;
  keep: number;
  next_run_at: string | null;
  last_run_at: string | null;
  last_status: 'ok' | 'failed' | null;
  last_message: string | null;
  description?: string;
}

export interface BackupOverview {
  backups: BackupItem[];
  schedule: BackupSchedule;
  frequencies: Array<{ key: string; label: string }>;
  history: Array<{ at: string; event: string; id: string | null; who: string; message: string }>;
  disk: { used: number; free: number | null };
  database: { driver: string; tables: number };
  current: BackupStats & { last_ticket_number: string | null };
}

export interface BackupRestorePlan {
  token: string;
  expires_in: number;
  phrase: string;
  backup: BackupItem;
  current: BackupStats & { last_ticket_number: string | null };
  warnings: string[];
  tables_to_clear: string[];
  summary: string[];
}

@Injectable({
  providedIn: 'root'
})
export class AdminService {
  private apiUrl = environment.apiUrl;

  constructor(private http: HttpClient) {}

  // Movies
  importCatalog(data: unknown, dryRun: boolean, updateExisting: boolean, type: CatalogImportType = 'movie'): Observable<CatalogImportResult> {
    return this.http.post<CatalogImportResult>(`${this.apiUrl}/catalog-import`, { data, type, dry_run: dryRun, update_existing: updateExisting });
  }

  getCatalogImportSample(type: CatalogImportType = 'movie'): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/catalog-import/sample`, { params: { type } });
  }

  getMovies(): Observable<Movie[]> {
    return this.http.get<Movie[]>(`${this.apiUrl}/movies-admin`);
  }

  getMovie(id: number): Observable<Movie> {
    return this.http.get<Movie>(`${this.apiUrl}/movies-admin/${id}`);
  }

  createMovie(movie: Partial<Movie>): Observable<Movie> {
    return this.http.post<Movie>(`${this.apiUrl}/movies-admin`, movie);
  }

  createMovieWithFile(formData: FormData): Observable<Movie> {
    return this.http.post<Movie>(`${this.apiUrl}/movies-admin`, formData);
  }

  updateMovie(id: number, movie: Partial<Movie>): Observable<Movie> {
    return this.http.put<Movie>(`${this.apiUrl}/movies-admin/${id}`, movie);
  }

  updateMovieWithFile(id: number, formData: FormData): Observable<Movie> {
    return this.http.post<Movie>(`${this.apiUrl}/movies-admin/${id}/upload`, formData);
  }

  deleteMovie(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/movies-admin/${id}`);
  }

  // Theater Shows
  getTheaterShows(): Observable<TheaterShow[]> {
    return this.http.get<TheaterShow[]>(`${this.apiUrl}/theater-shows-admin`);
  }

  getTheaterShow(id: number): Observable<TheaterShow> {
    return this.http.get<TheaterShow>(`${this.apiUrl}/theater-shows-admin/${id}`);
  }

  createTheaterShowWithFile(formData: FormData): Observable<TheaterShow> {
    return this.http.post<TheaterShow>(`${this.apiUrl}/theater-shows-admin`, formData);
  }

  updateTheaterShowWithFile(id: number, formData: FormData): Observable<TheaterShow> {
    return this.http.post<TheaterShow>(`${this.apiUrl}/theater-shows-admin/${id}/upload`, formData);
  }

  deleteTheaterShow(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/theater-shows-admin/${id}`);
  }

  // Showtimes
  getShowtimes(): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/showtimes-admin`);
  }

  getShowtime(id: number): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/showtimes-admin/${id}`);
  }

  createShowtime(showtime: any): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/showtimes-admin`, showtime);
  }

  updateShowtime(id: number, showtime: any): Observable<any> {
    return this.http.put<any>(`${this.apiUrl}/showtimes-admin/${id}`, showtime);
  }

  deleteShowtime(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/showtimes-admin/${id}`);
  }

  // Reservations
  getReservations(): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/reservations`);
  }

  getGroupedReservations(): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/reservations/grouped`);
  }

  startReservationPayment(reservationId: number, method: 'cash' | 'card'): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/reservations/${reservationId}/start-payment`, { method });
  }

  deleteReservation(reservationId: number): Observable<any> {
    return this.http.delete<any>(`${this.apiUrl}/reservations/${reservationId}`);
  }

  // Reports
  getDailyReport(date: string): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/reports/daily?date=${date}`);
  }

  getSalesStats(from: string, to: string, group: 'hour' | 'day' | 'week' | 'month' | 'year'): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/reports/sales`, { params: { from, to, group } });
  }

  // Helyszíni jegyeladás
  getShowtimeReservations(showtimeId: number): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/showtimes/${showtimeId}/reservations`);
  }

  posSell(payload: {
    showtime_id: number;
    seatKeys: string[];
    payment_method: PaymentMethod;
    customer_name?: string;
    seat_categories?: Record<string, TicketCategory>;
  }): Observable<{ reservation: any; tickets: Ticket[] }> {
    return this.http.post<{ reservation: any; tickets: Ticket[] }>(`${this.apiUrl}/pos/sell`, payload);
  }

  // Egyéb szolgáltatások értékesítése (nyugtával)
  posServices(): Observable<{ services: Array<{ id: number; name: string; price: number }>; staff_price: number }> {
    return this.http.get<{ services: Array<{ id: number; name: string; price: number }>; staff_price: number }>(`${this.apiUrl}/pos/services`);
  }

  posSellServices(payload: {
    items: Array<{ service_id: number; quantity: number }>;
    payment_method: PaymentMethod;
  }): Observable<{ tickets: Ticket[]; total: number }> {
    return this.http.post<{ tickets: Ticket[]; total: number }>(`${this.apiUrl}/pos/services/sell`, payload);
  }

  posServiceTickets(): Observable<Ticket[]> {
    return this.http.get<Ticket[]>(`${this.apiUrl}/pos/services/tickets`);
  }

  posSearchCustomers(q: string): Observable<Array<{ id: number; name: string; email: string | null; phone: string | null; zip: string | null }>> {
    return this.http.get<Array<{ id: number; name: string; email: string | null; phone: string | null; zip: string | null }>>(`${this.apiUrl}/pos/customers`, { params: { q } });
  }

  posHold(payload: {
    showtime_id: number;
    seatKeys: string[];
    customer_name: string;
    customer_phone: string;
    customer_email?: string;
    customer_zip?: string;
    seat_categories?: Record<string, TicketCategory>;
  }): Observable<{ reservation: any; tickets: Ticket[] }> {
    return this.http.post<{ reservation: any; tickets: Ticket[] }>(`${this.apiUrl}/pos/hold`, payload);
  }

  posUpdateReservation(reservationId: number, payload: {
    customer_name: string;
    customer_phone: string;
    customer_email?: string;
    seatKeys?: string[];
  }): Observable<{ reservation: any; tickets: Ticket[] }> {
    return this.http.put<{ reservation: any; tickets: Ticket[] }>(`${this.apiUrl}/pos/reservations/${reservationId}`, payload);
  }

  posVoidTicket(ticketId: number, reason?: string): Observable<{ voided: string; nav_correction_needed: boolean }> {
    return this.http.post<{ voided: string; nav_correction_needed: boolean }>(`${this.apiUrl}/pos/tickets/${ticketId}/void`, { reason });
  }

  posShowtimeTickets(showtimeId: number): Observable<Array<Ticket & { voidable: boolean }>> {
    return this.http.get<Array<Ticket & { voidable: boolean }>>(`${this.apiUrl}/pos/showtimes/${showtimeId}/tickets`);
  }

  posDeleteReservation(reservationId: number): Observable<any> {
    return this.http.delete<any>(`${this.apiUrl}/pos/reservations/${reservationId}`);
  }

  posPayReservation(reservationId: number, paymentMethod: PaymentMethod, seatKeys?: string[]): Observable<{ reservation: any; tickets: Ticket[] }> {
    return this.http.post<{ reservation: any; tickets: Ticket[] }>(
      `${this.apiUrl}/pos/reservations/${reservationId}/pay`,
      seatKeys ? { payment_method: paymentMethod, seat_keys: seatKeys } : { payment_method: paymentMethod }
    );
  }

  // Eladott jegyek archívuma
  getTicketYears(): Observable<number[]> {
    return this.http.get<number[]>(`${this.apiUrl}/tickets/years`);
  }

  getTickets(params: { year: number; page?: number; payment_method?: string; date?: string; q?: string }): Observable<TicketPage> {
    const query = Object.entries(params)
      .filter(([, value]) => value !== undefined && value !== null && value !== '')
      .map(([key, value]) => `${key}=${encodeURIComponent(String(value))}`)
      .join('&');
    return this.http.get<TicketPage>(`${this.apiUrl}/tickets?${query}`);
  }

  exportTickets(params: { year: number; payment_method?: string; date?: string; q?: string }): Observable<Blob> {
    const query: Record<string, string> = { year: String(params.year) };
    if (params.payment_method) query['payment_method'] = params.payment_method;
    if (params.date) query['date'] = params.date;
    if (params.q) query['q'] = params.q;

    return this.http.get(`${this.apiUrl}/tickets/export`, { params: query, responseType: 'blob' });
  }

  // Egy nap előadásainak teljes jegyeladása
  getShowtimeDay(date: string): Observable<ShowtimeDay> {
    return this.http.get<ShowtimeDay>(`${this.apiUrl}/tickets/showtime-day`, { params: { date } });
  }

  exportShowtimeDay(date: string, type: 'summary' | 'tickets' = 'tickets'): Observable<Blob> {
    return this.http.get(`${this.apiUrl}/tickets/showtime-day/export`, { params: { date, type }, responseType: 'blob' });
  }

  getDailyClose(date: string): Observable<DailyClose> {
    return this.http.get<DailyClose>(`${this.apiUrl}/tickets/daily-close?date=${date}`);
  }

  getSalesLog(date: string): Observable<{ date: string; logs: SalesLogEntry[] }> {
    return this.http.get<{ date: string; logs: SalesLogEntry[] }>(`${this.apiUrl}/tickets/sales-log?date=${date}`);
  }

  // Beállítások: jegyszámozás
  getTicketNumbering(): Observable<TicketNumberingState> {
    return this.http.get<TicketNumberingState>(`${this.apiUrl}/settings/ticket-numbering`);
  }

  prepareTicketNumbering(payload: { action: 'prefix' | 'counter'; prefix?: string; mode?: 'continue' | 'reset'; value?: number | string; password: string }): Observable<TicketNumberingPlan> {
    return this.http.post<TicketNumberingPlan>(`${this.apiUrl}/settings/ticket-numbering/prepare`, payload);
  }

  applyTicketNumbering(payload: { token: string; phrase: string; password: string }): Observable<{ status: string; message: string; state: TicketNumberingState }> {
    return this.http.post<{ status: string; message: string; state: TicketNumberingState }>(`${this.apiUrl}/settings/ticket-numbering/apply`, payload);
  }

  // Beállítások: NAV kapcsolat (nyugtaadat-szolgáltatás)
  getNav(): Observable<NavState> {
    return this.http.get<NavState>(`${this.apiUrl}/settings/nav`);
  }

  saveNav(payload: {
    enabled: boolean; environment: string; tax_number: string; login: string; tech_password?: string;
    signature_key?: string; software_name: string; vat_category: string; password: string;
  }): Observable<NavState> {
    return this.http.put<NavState>(`${this.apiUrl}/settings/nav`, payload);
  }

  saveNavSchedule(payload: { auto_enabled: boolean; auto_time: string; catchup_days: number }): Observable<NavState> {
    return this.http.put<NavState>(`${this.apiUrl}/settings/nav/schedule`, payload);
  }

  testNav(): Observable<NavTestResult> {
    return this.http.post<NavTestResult>(`${this.apiUrl}/settings/nav/test`, {});
  }

  registerNavSoftware(): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.apiUrl}/settings/nav/software`, {});
  }

  previewNav(date: string): Observable<NavPreview> {
    return this.http.get<NavPreview>(`${this.apiUrl}/settings/nav/preview`, { params: { date } });
  }

  prepareNavSend(payload: { date: string; modify: boolean; password: string }): Observable<NavSendPlan> {
    return this.http.post<NavSendPlan>(`${this.apiUrl}/settings/nav/send/prepare`, payload);
  }

  applyNavSend(payload: { token: string; phrase: string; password: string; acknowledge: boolean }): Observable<{ status: string; submission: NavSubmissionItem; state: NavState }> {
    return this.http.post<{ status: string; submission: NavSubmissionItem; state: NavState }>(`${this.apiUrl}/settings/nav/send/apply`, payload);
  }

  // Beállítások: teszt üzem jelzés
  getTestMode(): Observable<{ test_mode: boolean }> {
    return this.http.get<{ test_mode: boolean }>(`${this.apiUrl}/settings/test-mode`);
  }

  saveTestMode(testMode: boolean): Observable<{ test_mode: boolean }> {
    return this.http.put<{ test_mode: boolean }>(`${this.apiUrl}/settings/test-mode`, { test_mode: testMode });
  }

  // Beállítások: karbantartás mód
  getMaintenance(): Observable<{ maintenance: boolean; message: string; default_message: string }> {
    return this.http.get<{ maintenance: boolean; message: string; default_message: string }>(`${this.apiUrl}/settings/maintenance`);
  }

  saveMaintenance(maintenance: boolean, message: string): Observable<{ maintenance: boolean; message: string; default_message: string }> {
    return this.http.put<{ maintenance: boolean; message: string; default_message: string }>(`${this.apiUrl}/settings/maintenance`, { maintenance, message });
  }

  // Beállítások: OTP SimplePay
  getSimplePay(): Observable<SimplePaySettings> {
    return this.http.get<SimplePaySettings>(`${this.apiUrl}/settings/simplepay`);
  }

  saveSimplePay(payload: {
    enabled: boolean; environment: string; merchant: string; secret_key?: string; language: string;
    timeout_minutes: number; site_url: string; password: string;
  }): Observable<SimplePaySettings> {
    return this.http.put<SimplePaySettings>(`${this.apiUrl}/settings/simplepay`, payload);
  }

  testSimplePay(): Observable<SimplePayTestResult> {
    return this.http.post<SimplePayTestResult>(`${this.apiUrl}/settings/simplepay/test`, {});
  }

  // Beállítások: egyéb szolgáltatások
  getExtraServices(): Observable<ExtraService[]> {
    return this.http.get<ExtraService[]>(`${this.apiUrl}/settings/extra-services`);
  }

  createExtraService(payload: { name: string; price: number }): Observable<ExtraService> {
    return this.http.post<ExtraService>(`${this.apiUrl}/settings/extra-services`, payload);
  }

  updateExtraService(id: number, payload: { name: string; price: number }): Observable<ExtraService> {
    return this.http.put<ExtraService>(`${this.apiUrl}/settings/extra-services/${id}`, payload);
  }

  deleteExtraService(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/settings/extra-services/${id}`);
  }

  // Beállítások: Számlázz.hu
  getSzamlazz(): Observable<SzamlazzSettings> {
    return this.http.get<SzamlazzSettings>(`${this.apiUrl}/settings/szamlazz`);
  }

  saveSzamlazz(payload: {
    enabled: boolean; agent_key?: string; vat_rate: string; e_invoice: boolean; send_email: boolean;
    prefix: string; email_subject: string; email_text: string; password: string;
  }): Observable<SzamlazzSettings> {
    return this.http.put<SzamlazzSettings>(`${this.apiUrl}/settings/szamlazz`, payload);
  }

  testSzamlazz(): Observable<{ ok: boolean; message: string }> {
    return this.http.post<{ ok: boolean; message: string }>(`${this.apiUrl}/settings/szamlazz/test`, {});
  }

  // Helyszíni ÁFÁS számla
  posInvoicing(): Observable<{ enabled: boolean; vat_rate: string }> {
    return this.http.get<{ enabled: boolean; vat_rate: string }>(`${this.apiUrl}/pos/invoicing`);
  }

  posIssueInvoice(reservationId: number, buyer: InvoiceBuyer): Observable<PosInvoice> {
    return this.http.post<PosInvoice>(`${this.apiUrl}/pos/reservations/${reservationId}/invoice`, buyer);
  }

  posInvoicePdf(invoiceId: number): Observable<Blob> {
    return this.http.get(`${this.apiUrl}/pos/invoices/${invoiceId}/pdf`, { responseType: 'blob' });
  }

  // Beállítások: biztonsági mentés
  getBackups(): Observable<BackupOverview> {
    return this.http.get<BackupOverview>(`${this.apiUrl}/settings/backups`);
  }

  createBackup(): Observable<BackupItem> {
    return this.http.post<BackupItem>(`${this.apiUrl}/settings/backups`, {});
  }

  downloadBackup(id: string): Observable<Blob> {
    return this.http.get(`${this.apiUrl}/settings/backups/${id}/download`, { responseType: 'blob' });
  }

  deleteBackup(id: string): Observable<{ status: string }> {
    return this.http.delete<{ status: string }>(`${this.apiUrl}/settings/backups/${id}`);
  }

  uploadBackup(file: File): Observable<BackupItem> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<BackupItem>(`${this.apiUrl}/settings/backups/upload`, form);
  }

  saveBackupSchedule(schedule: { enabled: boolean; frequency: string; time: string; weekday: number; keep: number }): Observable<BackupSchedule> {
    return this.http.put<BackupSchedule>(`${this.apiUrl}/settings/backups/schedule`, schedule);
  }

  prepareBackupRestore(id: string, password: string): Observable<BackupRestorePlan> {
    return this.http.post<BackupRestorePlan>(`${this.apiUrl}/settings/backups/${id}/restore/prepare`, { password });
  }

  applyBackupRestore(id: string, payload: { token: string; phrase: string; password: string; acknowledge: boolean }): Observable<{ status: string; message: string; safety_backup: BackupItem }> {
    return this.http.post<{ status: string; message: string; safety_backup: BackupItem }>(`${this.apiUrl}/settings/backups/${id}/restore/apply`, payload);
  }

  // E-mail sablonok
  getEmailTemplates(): Observable<EmailTemplatesResponse> {
    return this.http.get<EmailTemplatesResponse>(`${this.apiUrl}/email-templates`);
  }

  saveEmailTemplate(key: string, payload: { subject: string; body: string; is_active: boolean }): Observable<EmailTemplate> {
    return this.http.put<EmailTemplate>(`${this.apiUrl}/email-templates/${key}`, payload);
  }

  resetEmailTemplate(key: string): Observable<EmailTemplate> {
    return this.http.post<EmailTemplate>(`${this.apiUrl}/email-templates/${key}/reset`, {});
  }

  previewEmailTemplate(key: string, payload: { subject: string; body: string }): Observable<{ subject: string; html: string }> {
    return this.http.post<{ subject: string; html: string }>(`${this.apiUrl}/email-templates/${key}/preview`, payload);
  }

  testEmailTemplate(key: string, payload: { subject: string; body: string; to: string }): Observable<{ status: string; to: string; delivers: boolean }> {
    return this.http.post<{ status: string; to: string; delivers: boolean }>(`${this.apiUrl}/email-templates/${key}/test`, payload);
  }

  // Tevékenységnapló
  getActivityMeta(): Observable<ActivityMeta> {
    return this.http.get<ActivityMeta>(`${this.apiUrl}/activity-logs/meta`);
  }

  getActivityLogs(params: Record<string, string | number | undefined>): Observable<ActivityLogPage> {
    const query = Object.entries(params)
      .filter(([, value]) => value !== undefined && value !== null && value !== '')
      .map(([key, value]) => `${key}=${encodeURIComponent(String(value))}`)
      .join('&');
    return this.http.get<ActivityLogPage>(`${this.apiUrl}/activity-logs?${query}`);
  }

  // A nyomtatás csak a böngészőben látszik, ezért onnan jelentjük a szervernek
  logClientEvent(action: 'ticket.print' | 'report.daily_close_print', description: string, details: object = {}): void {
    this.http.post(`${this.apiUrl}/activity-logs/event`, { action, description, details }).subscribe({ error: () => {} });
  }

  // Auditoria
  getAuditoria(): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/auditoria`);
  }

  getAuditorium(id: number): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/auditoria/${id}`);
  }

  getAuditoriumSeats(id: number): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/auditoria/${id}/seats`);
  }

  updateAuditoriumSeats(id: number, seats: any[], sectors: any[], sections: any[], rows: any[]): Observable<any> {
    return this.http.put<any>(`${this.apiUrl}/auditoria/${id}/seats`, { seats, sectors, sections, rows });
  }

  createAuditorium(auditorium: any): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/auditoria`, auditorium);
  }

  updateAuditorium(id: number, auditorium: any): Observable<any> {
    return this.http.put<any>(`${this.apiUrl}/auditoria/${id}`, auditorium);
  }

  deleteAuditorium(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/auditoria/${id}`);
  }

  // Users
  getUsers(): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/users`);
  }

  getRoles(): Observable<Array<{ key: string; label: string; permissions: string[] }>> {
    return this.http.get<Array<{ key: string; label: string; permissions: string[] }>>(`${this.apiUrl}/roles`);
  }

  getUser(id: number): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/users/${id}`);
  }

  createUser(user: any): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/users`, user);
  }

  updateUser(id: number, user: any): Observable<any> {
    return this.http.put<any>(`${this.apiUrl}/users/${id}`, user);
  }

  deleteUser(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/users/${id}`);
  }

  // Customers
  getCustomers(search = ''): Observable<any[]> {
    return this.http.get<any[]>(`${this.apiUrl}/customers`, { params: search ? { search } : {} });
  }

  createCustomer(customer: any): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/customers`, customer);
  }

  updateCustomer(id: number, customer: any): Observable<any> {
    return this.http.put<any>(`${this.apiUrl}/customers/${id}`, customer);
  }

  deleteCustomer(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/customers/${id}`);
  }
}
