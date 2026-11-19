import Link from "next/link";
import type { ReactNode } from "react";
import { dispatch } from "./actions";
import type { AdminSession } from "./lib/auth";

export function Notice({
  message,
  type = "success",
}: {
  message?: string;
  type?: string;
}) {
  if (!message) return null;
  return <div className={`notice ${type === "error" ? "error" : type === "warning" ? "warning" : "success"}`} role="status">{message}</div>;
}

export function PageHead({
  title,
  subtitle,
  actions,
}: {
  title: string;
  subtitle?: string;
  actions?: ReactNode;
}) {
  return (
    <div className="page-head">
      <div>
        <p className="eyebrow">SISTEM ADMINISTRASI DOKUMEN</p>
        <h1>{title}</h1>
        {subtitle && <p className="muted">{subtitle}</p>}
      </div>
      <div className="head-actions">{actions}</div>
    </div>
  );
}

export function ButtonLink({
  href,
  children,
  className = "button",
}: {
  href: string;
  children: ReactNode;
  className?: string;
}) {
  return <Link className={className} href={href}>{children}</Link>;
}

export function ActionButton({
  label,
  action,
  fields = {},
  className = "button",
}: {
  label: string;
  action: string;
  fields?: Record<string, string | number>;
  className?: string;
}) {
  return (
    <form action={dispatch} className="inline-form">
      <input type="hidden" name="action" value={action} />
      {Object.entries(fields).map(([name, value]) => <input key={name} type="hidden" name={name} value={value} />)}
      <button className={className}>{label}</button>
    </form>
  );
}

export function Table({
  headers,
  rows,
  empty = "Belum ada data.",
}: {
  headers: string[];
  rows: ReactNode[][];
  empty?: string;
}) {
  if (rows.length === 0) {
    return <div className="empty-state"><strong>{empty}</strong><span>Data akan muncul di sini setelah dicatat.</span></div>;
  }
  return (
    <div className="table-wrap">
      <table>
        <thead><tr>{headers.map((header, index) => <th key={`${header}-${index}`}>{header}</th>)}</tr></thead>
        <tbody>{rows.map((row, index) => <tr key={index}>{row.map((cell, cellIndex) => <td key={cellIndex}>{cell}</td>)}</tr>)}</tbody>
      </table>
    </div>
  );
}

export function Badge({ status }: { status: string }) {
  const classes: Record<string, string> = {
    Tersedia: "available",
    Dipinjam: "borrowed",
    Terlambat: "overdue",
    Hilang: "lost",
    Nonaktif: "inactive",
    Selesai: "available",
  };
  return <span className={`badge ${classes[status] || "inactive"}`}>{status}</span>;
}

export function PageShell({
  admin,
  page,
  children,
}: {
  admin: AdminSession;
  page: string;
  children: ReactNode;
}) {
  const nav = [
    ["dashboard", "Dashboard", "◫"],
    ["documents", "Dokumen", "▤"],
    ["borrowers", "Peminjam", "♙"],
    ["loans", "Peminjaman", "⇄"],
    ["reports", "Laporan", "▥"],
  ];
  return (
    <>
      <a className="skip-link" href="#main">Lewati ke konten utama</a>
      <div className="app-shell">
        <aside className="sidebar">
          <Link className="brand" href="/?page=dashboard">
            <img className="brand-mark" src="https://www.atrbpn.go.id/favicon.ico" alt="Logo ATR/BPN" />
            <span><strong>BTSU</strong><small>Arsip Pertanahan</small></span>
          </Link>
          <p className="nav-caption">MENU UTAMA</p>
          <nav aria-label="Navigasi utama">
            {nav.map(([key, label, icon]) => (
              <Link key={key} className={`nav-link ${page === key || (key === "documents" && page.startsWith("document-")) || (key === "borrowers" && page.startsWith("borrower-")) || (key === "loans" && page.startsWith("loan-")) ? "active" : ""}`} href={`/?page=${key}`}>
                <span className="nav-icon" aria-hidden="true">{icon}</span>{label}
              </Link>
            ))}
            {admin.role === "Super Admin" && <Link className={`nav-link ${page === "settings" ? "active" : ""}`} href="/?page=settings"><span className="nav-icon" aria-hidden="true">⚙</span>Pengaturan</Link>}
          </nav>
          <div className="sidebar-bottom">
            <span className="avatar">{admin.name.slice(0, 1).toUpperCase()}</span>
            <div><strong>{admin.name}</strong><small>{admin.role}</small></div>
          </div>
        </aside>
        <div className="app-main">
          <header className="topbar">
            <div><span className="topbar-label">Tata Kelola Buku Tanah dan Surat Ukur</span><span className="topbar-location">Kota Semarang</span></div>
            <div className="topbar-actions">
              <Link href="/?page=password">Ubah kata sandi</Link>
              <form action={dispatch} className="inline-form"><input type="hidden" name="action" value="logout" /><button className="button">Keluar</button></form>
            </div>
          </header>
          <main id="main" className={`main-content ${page === "borrowers" ? "borrowers-page" : ""}`}>
            {children}
            <footer className="footer">BTSU · Sistem Administrasi Dokumen · {new Date().getFullYear()}</footer>
          </main>
        </div>
      </div>
    </>
  );
}

export function formatDate(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00+07:00`);
  return new Intl.DateTimeFormat("id-ID", { day: "2-digit", month: "short", year: "numeric", timeZone: "Asia/Jakarta" }).format(date);
}

export function pageHref(page: string, values: Record<string, string | number | undefined> = {}) {
  const params = new URLSearchParams({ page });
  for (const [key, value] of Object.entries(values)) {
    if (value !== undefined && String(value) !== "") params.set(key, String(value));
  }
  return `/?${params.toString()}`;
}

export function InlineAction({
  action,
  fields,
  label,
  className = "button",
}: {
  action: string;
  fields: Record<string, string | number>;
  label: string;
  className?: string;
}) {
  return <ActionButton action={action} fields={fields} label={label} className={className} />;
}
