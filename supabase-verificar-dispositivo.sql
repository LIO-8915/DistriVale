-- ============================================================
-- Adición al esquema de Supabase para DistriVale v0.9.1
-- Pegar y ejecutar en Supabase → SQL Editor (después de
-- AdminDistriVale/supabase-setup.sql, que ya debe estar aplicado)
-- ============================================================

-- verificar_licencia() (ya existente) requiere auth.uid(), es decir
-- una sesión completa de Supabase Auth con contraseña — pero el
-- flujo de activación de DistriVale nunca le pide contraseña al
-- comprador, solo un código de un solo uso. Esta versión hace el
-- mismo chequeo (cuenta aprobada + dispositivo activo) recibiendo
-- el id de cuenta y la huella como parámetros explícitos en vez de
-- depender de una sesión — coherente con "sin login por contraseña
-- en el escritorio".
create or replace function verificar_dispositivo(p_cuenta_id uuid, p_huella text)
returns json
language plpgsql
security definer
set search_path = public
as $$
declare
  v_perfil perfiles%rowtype;
  v_dispositivo dispositivos%rowtype;
begin
  select * into v_perfil from perfiles where id = p_cuenta_id;
  if not found or v_perfil.estado <> 'aprobado' then
    return json_build_object('ok', false, 'error', 'cuenta_no_aprobada');
  end if;

  select * into v_dispositivo from dispositivos
    where cuenta_id = p_cuenta_id and huella = p_huella;
  if not found or v_dispositivo.estado <> 'activo' then
    return json_build_object('ok', false, 'error', 'dispositivo_no_activo');
  end if;

  update dispositivos set ultima_vez = now() where id = v_dispositivo.id;

  return json_build_object('ok', true);
end;
$$;

grant execute on function verificar_dispositivo(uuid, text) to anon;
