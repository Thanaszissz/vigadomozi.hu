import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AdminService } from '../../../../services/admin.service';

interface User {
  id?: number;
  name: string;
  email: string;
  password?: string;
  created_at?: string;
}

@Component({
  selector: 'app-users-admin',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './users-admin.component.html',
  styleUrls: ['./users-admin.component.css']
})
export class UsersAdminComponent implements OnInit {
  users: User[] = [];
  loading = false;
  error: string | null = null;
  successMessage: string | null = null;

  // Form state
  showForm = false;
  editingId: number | null = null;
  formData: User = {
    name: '',
    email: '',
    password: ''
  };

  constructor(
    private adminService: AdminService,
    private router: Router
  ) {}

  ngOnInit() {
    this.loadUsers();
  }

  loadUsers() {
    this.loading = true;
    this.error = null;

    this.adminService.getUsers().subscribe({
      next: (users) => {
        this.users = users;
        this.loading = false;
      },
      error: (err) => {
        this.error = 'Felhasználók betöltése sikertelen.';
        console.error(err);
        this.loading = false;
      }
    });
  }

  toggleForm() {
    this.showForm = !this.showForm;
    if (!this.showForm) {
      this.resetForm();
    }
  }

  resetForm() {
    this.formData = { name: '', email: '', password: '' };
    this.editingId = null;
    this.error = null;
  }

  editUser(user: User) {
    this.editingId = user.id || null;
    this.formData = { ...user, password: '' };
    this.showForm = true;
  }

  saveUser() {
    if (!this.formData.name || !this.formData.email) {
      this.error = 'Név és email megadása kötelező.';
      return;
    }

    if (!this.editingId && !this.formData.password) {
      this.error = 'Új felhasználóhoz jelszó megadása kötelező.';
      return;
    }

    this.loading = true;
    this.error = null;
    this.successMessage = null;

    const saveRequest = this.editingId
      ? this.adminService.updateUser(this.editingId, this.formData)
      : this.adminService.createUser(this.formData);

    saveRequest.subscribe({
      next: () => {
        this.successMessage = this.editingId
          ? 'Felhasználó sikeresen módosítva.'
          : 'Felhasználó sikeresen létrehozva.';
        this.loadUsers();
        this.resetForm();
        this.showForm = false;
        this.loading = false;
        setTimeout(() => (this.successMessage = null), 3000);
      },
      error: (err) => {
        this.error = err.error?.message || 'Hiba a felhasználó mentésekor.';
        this.loading = false;
        console.error(err);
      }
    });
  }

  deleteUser(user: User) {
    if (!confirm(`Biztos, hogy törölni szeretnéd "${user.name}" felhasználót?`)) {
      return;
    }

    this.loading = true;
    this.error = null;

    this.adminService.deleteUser(user.id!).subscribe({
      next: () => {
        this.successMessage = 'Felhasználó sikeresen törölve.';
        this.loadUsers();
        this.loading = false;
        setTimeout(() => (this.successMessage = null), 3000);
      },
      error: (err) => {
        this.error = err.error?.message || 'Hiba a felhasználó törlésekor.';
        this.loading = false;
        console.error(err);
      }
    });
  }

  canDeleteUser(user: User): boolean {
    return true;
  }

  goBack() {
    this.router.navigate(['/admin/dashboard']);
  }
}
