import { Component } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-admin-login',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div class="admin-login-container">
      <div class="login-card">
        <h1>🎬 VIGADÓ Admin</h1>
        <p class="subtitle">Bejelentkezés</p>

        <form [formGroup]="loginForm" (ngSubmit)="onSubmit()">
          <div class="form-group">
            <label>Email</label>
            <input 
              type="email" 
              formControlName="email" 
              placeholder="admin&#64;vigado.hu"
              class="form-input"
            />
            <span class="error" *ngIf="loginForm.get('email')?.invalid && loginForm.get('email')?.touched">
              Érvénytelen email
            </span>
          </div>

          <div class="form-group">
            <label>Jelszó</label>
            <input 
              type="password" 
              formControlName="password" 
              placeholder="••••••••"
              class="form-input"
            />
            <span class="error" *ngIf="loginForm.get('password')?.invalid && loginForm.get('password')?.touched">
              A jelszó legalább 6 karakter
            </span>
          </div>

          <div class="alert alert-danger" *ngIf="error">
            {{ error }}
          </div>

          <button 
            type="submit" 
            class="btn-login"
            [disabled]="loginForm.invalid || loading"
          >
            <span *ngIf="loading">⏳ Bejelentkezés...</span>
            <span *ngIf="!loading">🔓 Bejelentkezés</span>
          </button>
        </form>

        <div class="test-credentials">
          <p><strong>Teszt bejelentkezés:</strong></p>
          <p>📧 Email: admin&#64;vigado.hu</p>
          <p>🔑 Jelszó: admin123</p>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .admin-login-container {
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      padding: 20px;
    }

    .login-card {
      background: white;
      border-radius: 12px;
      padding: 40px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
      max-width: 400px;
      width: 100%;
    }

    h1 {
      text-align: center;
      font-size: 28px;
      margin: 0 0 10px;
      color: #333;
    }

    .subtitle {
      text-align: center;
      color: #666;
      margin-bottom: 30px;
      font-size: 14px;
    }

    .form-group {
      margin-bottom: 20px;
      display: flex;
      flex-direction: column;
    }

    label {
      font-weight: 600;
      margin-bottom: 8px;
      color: #333;
      font-size: 14px;
    }

    .form-input {
      padding: 12px 15px;
      border: 2px solid #e0e0e0;
      border-radius: 6px;
      font-size: 14px;
      transition: all 0.3s;
      font-family: inherit;
    }

    .form-input:focus {
      outline: none;
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .form-input:invalid {
      border-color: #ff6b6b;
    }

    .error {
      color: #ff6b6b;
      font-size: 12px;
      margin-top: 5px;
    }

    .alert {
      padding: 12px 15px;
      border-radius: 6px;
      margin-bottom: 20px;
      font-size: 14px;
    }

    .alert-danger {
      background-color: #ffe0e0;
      color: #d63031;
      border: 1px solid #ff6b6b;
    }

    .btn-login {
      width: 100%;
      padding: 12px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border: none;
      border-radius: 6px;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.3s;
      margin-bottom: 20px;
    }

    .btn-login:hover:not(:disabled) {
      transform: translateY(-2px);
      box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
    }

    .btn-login:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    .test-credentials {
      background: #f5f5f5;
      padding: 15px;
      border-radius: 6px;
      font-size: 12px;
      color: #666;
    }

    .test-credentials p {
      margin: 5px 0;
    }

    .test-credentials strong {
      color: #333;
    }
  `]
})
export class AdminLoginComponent {
  loginForm: FormGroup;
  loading = false;
  error: string | null = null;

  constructor(
    private fb: FormBuilder,
    private authService: AuthService,
    private router: Router
  ) {
    this.loginForm = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required, Validators.minLength(6)]]
    });
  }

  onSubmit(): void {
    if (this.loginForm.invalid) return;

    this.loading = true;
    this.error = null;

    const { email, password } = this.loginForm.value;

    this.authService.login(email, password).subscribe({
      next: () => {
        this.router.navigate(['/admin/dashboard']);
      },
      error: (err) => {
        this.error = err.error?.message || 'Bejelentkezés sikertelen. Ellenőrizze az adatokat!';
        this.loading = false;
      }
    });
  }
}
