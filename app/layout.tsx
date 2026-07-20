import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Casa Viva | Tienda online para el hogar",
  description: "Base inicial de Casa Viva, una tienda online cubana de productos para el hogar.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="es">
      <body>{children}</body>
    </html>
  );
}
