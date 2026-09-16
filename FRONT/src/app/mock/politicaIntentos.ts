/**
 * Copia de `back/src/Seguridad/PoliticaIntentos.php`: si cambia una, cambia la
 * otra. Mantener la regla pura evita que el simulador acepte logins que PHP
 * frenaría (o al revés).
 */
export const FALLOS_TOLERADOS = 5;
export const ESPERA_INICIAL = 60;
export const ESPERA_MAXIMA = 900;
export const VENTANA = 900;

/** La espera total que corresponde a una cantidad de fallos seguidos. */
export function espera(fallos: number): number {
  if (fallos < FALLOS_TOLERADOS) return 0;

  const duplicaciones = Math.min(fallos - FALLOS_TOLERADOS, 10);
  return Math.min(ESPERA_INICIAL * 2 ** duplicaciones, ESPERA_MAXIMA);
}

/** Si el último fallo ya quedó fuera de la ventana, deja de contar. */
export function olvidado(segundosDesdeUltimoFallo: number): boolean {
  return segundosDesdeUltimoFallo >= VENTANA;
}

/** Segundos que faltan para habilitar otro intento. */
export function segundosDeEspera(fallos: number, segundosDesdeUltimoFallo: number): number {
  if (olvidado(segundosDesdeUltimoFallo) || fallos < FALLOS_TOLERADOS) return 0;
  return Math.max(0, espera(fallos) - segundosDesdeUltimoFallo);
}
