import { Pool, type QueryResultRow } from "pg";

declare global {
  // eslint-disable-next-line no-var
  var btsuPool: Pool | undefined;
}

function connectionString(): string {
  const url = process.env.DATABASE_URL;
  if (url) return url;

  const host = process.env.DB_HOST;
  const password = process.env.DB_PASS;
  if (!host || !password) {
    throw new Error("Atur DATABASE_URL atau DB_HOST dan DB_PASS untuk Supabase.");
  }

  const user = process.env.DB_USER || "postgres";
  const database = process.env.DB_NAME || "postgres";
  const port = process.env.DB_PORT || "5432";
  const credentials = `${encodeURIComponent(user)}:${encodeURIComponent(password)}`;
  return `postgresql://${credentials}@${host}:${port}/${database}?sslmode=${process.env.DB_SSLMODE || "require"}`;
}

let singleton = globalThis.btsuPool;

function getPool(): Pool {
  if (singleton) return singleton;
  const instance = new Pool({
    connectionString: connectionString(),
    max: 3,
    idleTimeoutMillis: 10_000,
    connectionTimeoutMillis: 10_000,
  });
  singleton = instance;
  if (process.env.NODE_ENV !== "production") globalThis.btsuPool = instance;
  return instance;
}

export async function query<T = QueryResultRow>(
  text: string,
  values: unknown[] = [],
): Promise<T[]> {
  const result = await getPool().query(text, values);
  return result.rows as T[];
}

export async function transaction<T>(work: (client: import("pg").PoolClient) => Promise<T>): Promise<T> {
  const client = await getPool().connect();
  try {
    await client.query("BEGIN");
    const result = await work(client);
    await client.query("COMMIT");
    return result;
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
}
