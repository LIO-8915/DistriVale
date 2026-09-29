-- ============================================================
-- Licenciamiento vía Lemon Squeezy + auditoría en Supabase (v0.9.5)
-- Pegar y ejecutar en Supabase → SQL Editor, después de
-- AdminDistriVale/supabase-setup.sql y de supabase-verificar-dispositivo.sql
-- (ambos ya deben estar aplicados).
--
-- Cambio de enfoque respecto al esquema anterior: Lemon Squeezy pasa a
-- ser la ÚNICA fuente de verdad de si una licencia sigue activa y de
-- cuántos equipos puede tener activados (`activation_limit`). Supabase
-- deja de decidir eso — ahora es la capa de auditoría/anomalías: guarda
-- un espejo de cuenta/dispositivo, detecta inconsistencias, y le da a
-- AdminDistriVale dónde consultarlas.
--
-- El activar_dispositivo(correo, código) de un solo uso queda reemplazado
-- por registrar_cuenta_licencia(license_key_hash, ...), que ingresa la
-- license key de Lemon Squeezy directo. verificar_dispositivo() se
-- conserva tal cual, como "latido" (heartbeat) hacia Supabase en cada
-- arranque, después de que Lemon Squeezy ya confirmó que la licencia es
-- válida.
-- ============================================================

-- --- 1. Columnas nuevas en perfiles ---------------------------------

alter table perfiles
  add column if not exists license_key_hash text,
  add column if not exists limite_equipos integer not null default 1;

-- Único: es lo que hace estructuralmente imposible que una misma
-- licencia de Lemon Squeezy termine repartida en dos cuentas distintas
-- (no hace falta "detectarlo" después, la base de datos lo rechaza).
create unique index if not exists perfiles_license_key_hash_key
  on perfiles (license_key_hash)
  where license_key_hash is not null;

-- perfiles.id fue definida originalmente como
-- "uuid primary key references auth.users(id)", porque cada cuenta
-- nacía de un usuario real de Supabase Auth creado por AdminDistriVale
-- al aprobar una solicitud (flujo correo + código). Las cuentas que
-- ahora crea directo el escritorio a partir de una license key de Lemon
-- Squeezy NO pasan por Supabase Auth — no hay admin de por medio ni
-- contraseña que crear — así que necesitan poder existir sin un
-- auth.users correspondiente. Se quita esa restricción y se le da un
-- default para que el insert de la función de abajo no tenga que
-- inventar un id (nombre de la constraint confirmado con
-- `select conname, pg_get_constraintdef(oid) from pg_constraint
--  where conrelid = 'public.perfiles'::regclass and contype = 'f';`
-- contra el proyecto real de Supabase):
alter table perfiles drop constraint if exists perfiles_id_fkey;
alter table perfiles alter column id set default gen_random_uuid();
-- Las cuentas viejas (correo + código) siguen teniendo su id = el
-- auth.users original; esto no las toca, solo deja de exigir la FK
-- para las filas nuevas.

-- --- 2. Tabla de alertas ---------------------------------------------

create table if not exists alertas (
  id uuid primary key default gen_random_uuid(),
  cuenta_id uuid references perfiles(id) on delete cascade,
  tipo text not null,
  detalle text,
  creado_en timestamptz not null default now(),
  resuelta boolean not null default false
);

create index if not exists alertas_cuenta_id_idx on alertas (cuenta_id);
create index if not exists alertas_no_resueltas_idx on alertas (creado_en) where not resuelta;

-- --- 3. Tabla de logs de actividad (monitoreo local) -----------------

create table if not exists logs_actividad (
  id uuid primary key default gen_random_uuid(),
  cuenta_id uuid references perfiles(id) on delete cascade,
  huella text not null,
  tipo text not null,
  detalle text,
  creado_en timestamptz not null default now()
);

create index if not exists logs_actividad_cuenta_id_idx on logs_actividad (cuenta_id, creado_en desc);

-- --- 4. registrar_cuenta_licencia(): activación con license key ------
--
-- Se llama justo después de que Lemon Squeezy confirmó `activated: true`
-- para esa license key. Hace upsert de la cuenta por el hash de la key
-- (autoaprobada — la validez real ya la garantizó Lemon Squeezy) y
-- registra/actualiza el dispositivo. Si al insertar este dispositivo la
-- cuenta queda con más dispositivos activos de los que su plan permite
-- (`limite_equipos`), genera una alerta en vez de fallar silenciosamente
-- — en teoría Lemon Squeezy ya rechazó el activate en ese caso, así que
-- esto solo debería dispararse por una desincronización real.

create or replace function registrar_cuenta_licencia(
  p_license_key_hash text,
  p_huella text,
  p_instance_id text,
  p_nombre text,
  p_limite_equipos integer
)
returns json
language plpgsql
security definer
set search_path = public
as $$
declare
  v_cuenta_id uuid;
  v_dispositivos_activos integer;
begin
  insert into perfiles (license_key_hash, limite_equipos, estado)
    values (p_license_key_hash, p_limite_equipos, 'aprobado')
  on conflict (license_key_hash) do update
    set limite_equipos = excluded.limite_equipos
  returning id into v_cuenta_id;

  insert into dispositivos (cuenta_id, huella, nombre, lemonsqueezy_instance_id, estado, ultima_vez)
    values (v_cuenta_id, p_huella, p_nombre, p_instance_id, 'activo', now())
  on conflict (cuenta_id, huella) do update
    set lemonsqueezy_instance_id = excluded.lemonsqueezy_instance_id,
        estado = 'activo',
        ultima_vez = now();

  select count(*) into v_dispositivos_activos
    from dispositivos
    where cuenta_id = v_cuenta_id and estado = 'activo';

  if v_dispositivos_activos > p_limite_equipos then
    insert into alertas (cuenta_id, tipo, detalle)
      values (
        v_cuenta_id,
        'limite_equipos_excedido',
        format('%s dispositivos activos, límite de plan %s', v_dispositivos_activos, p_limite_equipos)
      );
  end if;

  return json_build_object('ok', true, 'cuenta_id', v_cuenta_id);
exception
  when others then
    return json_build_object('ok', false, 'error', sqlerrm);
end;
$$;

grant execute on function registrar_cuenta_licencia(text, text, text, integer) to anon;

-- --- 5. registrar_evento_monitoreo(): logs que sube el módulo local --
--
-- Todo lo que detecta el módulo de monitoreo local del escritorio
-- (huella cambiada, reloj manipulado, fallos repetidos de validación)
-- llega acá como log de auditoría. Los tipos marcados como sospechosos
-- también generan una fila en `alertas` para que no haya que revisar
-- todo `logs_actividad` a mano desde AdminDistriVale.

create or replace function registrar_evento_monitoreo(
  p_cuenta_id uuid,
  p_huella text,
  p_tipo text,
  p_detalle text
)
returns json
language plpgsql
security definer
set search_path = public
as $$
begin
  insert into logs_actividad (cuenta_id, huella, tipo, detalle)
    values (p_cuenta_id, p_huella, p_tipo, p_detalle);

  if p_tipo in ('reloj_manipulado', 'huella_cambiada', 'fallos_validacion_repetidos') then
    insert into alertas (cuenta_id, tipo, detalle)
      values (p_cuenta_id, p_tipo, p_detalle);
  end if;

  return json_build_object('ok', true);
exception
  when others then
    return json_build_object('ok', false, 'error', sqlerrm);
end;
$$;

grant execute on function registrar_evento_monitoreo(uuid, text, text, text) to anon;

-- --- 6. Nota sobre dispositivos.cuenta_id + huella --------------------
--
-- registrar_cuenta_licencia() depende de un índice único sobre
-- (cuenta_id, huella) en `dispositivos` para que el ON CONFLICT
-- funcione. Si `AdminDistriVale/supabase-setup.sql` no lo definió así
-- todavía, agregarlo:
--
-- create unique index if not exists dispositivos_cuenta_huella_key
--   on dispositivos (cuenta_id, huella);
--
-- y agregar la columna del instance_id de Lemon Squeezy si tampoco existe:
--
-- alter table dispositivos add column if not exists lemonsqueezy_instance_id text;
