-- 1. Entidades SIN oportunidades (agrupadas por dominio)
-- entidades que no tienen ninguna oportunidad asignada (probablemente shells o entidades huérfanas)
SELECT 
    e.id, 
    e.nombre, 
    e.dominio,
    (SELECT COUNT(*) FROM contacto WHERE entidad_id = e.id) AS contactos,
    e.created_at
FROM entidad e
LEFT JOIN oportunidad o ON o.entidad_id = e.id
WHERE o.id IS NULL
ORDER BY contactos DESC, e.nombre;

-- 2. Contactos SIN entidad (huérfanos — no deberían existir por el FK)
-- #2 → contactos que referencian un entidad_id que ya no existe (no debería pasar por el FK, pero a veces se rompe)
SELECT 
    c.id, 
    c.nombres, 
    c.email_contacto,
    c.entidad_id
FROM contacto c
LEFT JOIN entidad e ON c.entidad_id = e.id
WHERE e.id IS NULL
LIMIT 100;

-- 3. Resumen ejecutivo (lo más útil para depurar)
-- #3 → snapshot ejecutivo: si ves Contactos huérfanos > 0 hay inconsistencia seria; si ves Email duplicado > 0 hay merge mal hecho
SELECT 
    'Entidades TOTAL' AS metrica, COUNT(*) AS total FROM entidad
UNION ALL SELECT 'Entidades SIN oportunidades', COUNT(*) 
    FROM entidad e LEFT JOIN oportunidad o ON o.entidad_id=e.id WHERE o.id IS NULL
UNION ALL SELECT 'Entidades SIN contactos', COUNT(*)
    FROM entidad e LEFT JOIN contacto c ON c.entidad_id=e.id WHERE c.id IS NULL
UNION ALL SELECT 'Entidades CON dominio NULL', COUNT(*)
    FROM entidad WHERE dominio IS NULL OR dominio=''
UNION ALL SELECT 'Oportunidades SIN contacto_id', COUNT(*)
    FROM oportunidad WHERE contacto_id IS NULL
UNION ALL SELECT 'Contactos TOTAL', COUNT(*) FROM contacto
UNION ALL SELECT 'Contactos huérfanos', COUNT(*)
    FROM contacto c LEFT JOIN entidad e ON c.entidad_id=e.id WHERE e.id IS NULL
UNION ALL SELECT 'Contactos email duplicado', COUNT(*) FROM (
    SELECT email_contacto FROM contacto WHERE email_contacto IS NOT NULL AND email_contacto != ''
    GROUP BY email_contacto HAVING COUNT(*) > 1
) d;