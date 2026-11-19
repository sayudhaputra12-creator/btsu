import { NextRequest, NextResponse } from "next/server";
import { SignJWT, jwtVerify } from "jose";

const cookieName = "btsu_session";
const publicPages = new Set(["login", "forgot", "setup"]);

function secret(): Uint8Array | null {
  const value = process.env.SESSION_SECRET;
  return value && value.length >= 32 ? new TextEncoder().encode(value) : null;
}

export async function proxy(request: NextRequest) {
  const page = request.nextUrl.searchParams.get("page") || "dashboard";
  const key = secret();
  const token = request.cookies.get(cookieName)?.value;
  const isPublic = publicPages.has(page);

  if (!token || !key) {
    if (isPublic) return NextResponse.next();
    return NextResponse.redirect(new URL("/?page=login", request.url));
  }

  try {
    const { payload } = await jwtVerify(token, key);
    const lastActivity = Number(payload.lastActivity || 0);
    const now = Math.floor(Date.now() / 1000);
    if (!payload.sub || !lastActivity || now - lastActivity > 30 * 60) {
      const response = NextResponse.redirect(new URL("/?page=login&notice=Sesi%20berakhir&type=error", request.url));
      response.cookies.delete(cookieName);
      return response;
    }

    if (isPublic) return NextResponse.redirect(new URL("/?page=dashboard", request.url));
    const refreshed = await new SignJWT({ ...payload, lastActivity: now })
      .setProtectedHeader({ alg: "HS256" })
      .setSubject(payload.sub)
      .setIssuedAt(now)
      .setExpirationTime(now + 30 * 60)
      .sign(key);
    const response = NextResponse.next();
    response.cookies.set(cookieName, refreshed, {
      httpOnly: true,
      secure: request.nextUrl.protocol === "https:",
      sameSite: "lax",
      path: "/",
      maxAge: 30 * 60,
    });
    return response;
  } catch {
    if (isPublic) return NextResponse.next();
    const response = NextResponse.redirect(new URL("/?page=login", request.url));
    response.cookies.delete(cookieName);
    return response;
  }
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico|assets/|database/).*)"],
};
