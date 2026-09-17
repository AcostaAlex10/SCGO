// Copia de `back/src/Seguridad/PoliticaContrasena.php` (plan de producto, A-05).
//
// Si cambia una, cambia la otra: el simulador tiene que rechazar lo mismo que la
// API, o la demo estática deja pasar contraseñas que en producción fallan.
//
// El motivo de la regla está explicado en el archivo PHP; acá va solo la regla.

export const LARGO_MINIMO = 10;

const COMUNES = [
  "1234567890",
  "12345678910",
  "0987654321",
  "qwertyuiop",
  "contrasena",
  "contrasena1",
  "contrasena123",
  "contraseña123",
  "password123",
  "password1234",
  "passw0rd123",
  "administrador",
  "admin123456",
  "administrator",
  "iloveyou123",
  "bienvenido1",
  "usuario123",
  "constructora",
  "obras2026",
];

/** Devuelve el motivo del rechazo, o null si la contraseña sirve. */
export function validarContrasena(contrasena: string): string | null {
  // [...contrasena] cuenta caracteres, no unidades UTF-16: es el equivalente de
  // mb_strlen en PHP.
  if ([...contrasena].length < LARGO_MINIMO) {
    return `Debe tener al menos ${LARGO_MINIMO} caracteres`;
  }

  if (COMUNES.includes(contrasena.trim().toLowerCase())) {
    return "Es una de las contraseñas más usadas: elegí otra";
  }

  const caracteres = [...contrasena];
  if (caracteres.every((c) => c === caracteres[0])) {
    return "No puede ser un mismo carácter repetido";
  }

  return null;
}
