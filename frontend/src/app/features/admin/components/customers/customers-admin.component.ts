import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';

interface Customer {
  id?: number;
  name: string;
  email: string | null;
  phone: string | null;
  zip?: string | null;
  created_at?: string;
}

@Component({
  selector: 'app-customers-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './customers-admin.component.html',
  styleUrls: ['./customers-admin.component.css']
})
export class CustomersAdminComponent implements OnInit {
  customers: Customer[] = [];
  loading = false;
  error: string | null = null;
  successMessage: string | null = null;
  search = '';

  showForm = false;
  editingId: number | null = null;
  formData: Customer = { name: '', email: '', phone: '', zip: '' };

  constructor(
    private adminService: AdminService,
    private router: Router
  ) {}

  ngOnInit() {
    this.loadCustomers();
  }

  loadCustomers() {
    this.loading = true;
    this.adminService.getCustomers(this.search.trim()).subscribe({
      next: (customers) => {
        this.customers = customers;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Vásárlók betöltése sikertelen.';
        console.error(err);
        this.loading = false;
      }
    });
  }

  toggleForm() {
    if (this.showForm) {
      this.resetForm();
    } else {
      this.showForm = true;
    }
  }

  resetForm() {
    this.formData = { name: '', email: '', phone: '', zip: '' };
    this.editingId = null;
    this.error = null;
    this.showForm = false;
  }

  editCustomer(customer: Customer) {
    this.editingId = customer.id ?? null;
    this.formData = { name: customer.name, email: customer.email ?? '', phone: customer.phone ?? '', zip: customer.zip ?? '' };
    this.showForm = true;
    this.error = null;
  }

  saveCustomer() {
    if (!this.formData.name?.trim()) {
      this.error = 'A név megadása kötelező.';
      return;
    }

    this.loading = true;
    this.error = null;
    this.successMessage = null;

    const editing = this.editingId !== null;
    const request = editing
      ? this.adminService.updateCustomer(this.editingId!, this.formData)
      : this.adminService.createCustomer(this.formData);

    request.subscribe({
      next: () => {
        this.successMessage = editing ? 'Vásárló sikeresen módosítva.' : 'Vásárló sikeresen felvéve.';
        this.resetForm();
        this.loadCustomers();
        setTimeout(() => (this.successMessage = null), 3000);
      },
      error: (err) => {
        const errors = err.error?.errors ? Object.values(err.error.errors).flat().join(' ') : '';
        this.error = errors || err.error?.message || 'Hiba a vásárló mentésekor.';
        this.loading = false;
        console.error(err);
      }
    });
  }

  deleteCustomer(customer: Customer) {
    if (!confirm(`Biztos, hogy törölni szeretnéd "${customer.name}" vásárlót?`)) {
      return;
    }

    this.loading = true;
    this.error = null;

    this.adminService.deleteCustomer(customer.id!).subscribe({
      next: () => {
        this.successMessage = 'Vásárló sikeresen törölve.';
        this.loadCustomers();
        setTimeout(() => (this.successMessage = null), 3000);
      },
      error: (err) => {
        this.error = err.error?.message || 'Hiba a vásárló törlésekor.';
        this.loading = false;
        console.error(err);
      }
    });
  }

  goBack() {
    this.router.navigate(['/admin/dashboard']);
  }
}
