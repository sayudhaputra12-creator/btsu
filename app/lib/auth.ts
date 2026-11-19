import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { SignJWT, jwtVerify } from "jose";
import { query } from "./db";

export type AdminSession = {
  id: number;
  name: string;
  username: string;
  role: "Admin" | "Super Admin";
  lastActivity: number;
};

const cookieName = "btsu_session";
const secret = () => {
  const value = process.env.SESSION_SECRET;
  if (!value || value.length < 32) throw new Error("SESSION_SECRET harus berisi minimal 32 karakter.");
  return new TextEncoder().encode(value);
};

export async function createSession(admin: Omit<AdminSession, "lastActivity">): Promise<string> {
  const now = Math.floor(Date.now() / 1000);
  return new SignJWT({ ...admin, lastActivity: now })
    .setProtectedHeader({ alg: "HS256" })
    .setSubject(String(admin.id))
    .setIssuedAt(now)
    .setExpirationTime(now + 30 * 60)
    .sign(secret());
}

export async function readSession(token: string): Promise<AdminSession | null> {
  try {
    const { payload } = await jwtVerify(token, secret());
    if (
      typeof payload.sub !== "string" ||
      typeof payload.name !== "string" ||
      typeof payload.username !== "string" ||
      (payload.role !== "Admin" && payload.role !== "Super Admin") ||
      typeof payload.lastActivity !== "number"
    ) return null;
    return {
      id: Number(payload.sub),
      name: payload.name,
      username: payload.username,
      role: payload.role,
      lastActivity: payload.lastActivity,
    };
  } catch {
    return null;
  }
}

export async function getSession(): Promise<AdminSession | null> {
  const token = (await cookies()).get(cookieName)?.value;
  return token ? readSession(token) : null;
}

export async function requireAdmin(): Promise<AdminSession> {
  const session = await getSession();
  if (!session) redirect("/?page=login");
  const rows = await query<{ id: number; name: string; username: string; role: AdminSession["role"]; active: number }>(
    "SELECT id, name, username, role, active FROM admins WHERE id = $1",
    [session.id],
  );
  const admin = rows[0];
  if (!admin || Number(admin.active) !== 1) redirect("/?page=login&notice=Account%20inactive&type=error");
  return { ...session, name: admin.name, username: admin.username, role: admin.role };
}

export async function requireSuperAdmin(): Promise<AdminSession> {
  const admin = await requireAdmin();
  if (admin.role !== "Super Admin") redirect("/?page=dashboard&notice=Tidak%20memiliki%20akses&type=error");
  return admin;
}

export const sessionCookie = cookieName;
