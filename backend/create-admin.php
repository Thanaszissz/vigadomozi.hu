User::create([
    'name' => 'Admin',
    'email' => 'admin@vigado.hu',
    'password' => bcrypt('admin123'),
    'email_verified_at' => now()
]);

echo "✅ Admin user létrehozva!\n";
echo "📧 Email: admin@vigado.hu\n";
echo "🔑 Jelszó: admin123\n";
