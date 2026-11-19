import "../assets/styles.css";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "BTSU · Arsip Pertanahan",
  description: "Sistem administrasi Buku Tanah dan Surat Ukur",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="id">
      <body>{children}</body>
    </html>
  );
}
