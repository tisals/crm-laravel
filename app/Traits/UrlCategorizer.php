<?php

namespace App\Traits;

/**
 * Helpers para limpiar y categorizar URLs de páginas web.
 *
 * Permite distinguir entre un dominio real de empresa (ej: `polinter.com.co`)
 * y URLs de redes sociales (Facebook, Instagram, LinkedIn, Twitter). Esto
 * es necesario porque el seeder de oportunidades.csv lee la columna
 * `DOMINIO`, que en algunos casos contiene URLs sociales en vez del sitio
 * propio de la empresa.
 */
trait UrlCategorizer
{
    /**
     * Dominios de redes sociales reconocidos.
     * La lista se mantiene completa (cada red tiene su dominio principal + variants).
     */
    private const SOCIAL_DOMAINS = [
        // Facebook
        'facebook.com',
        'fb.com',
        'fb.me',
        // Instagram
        'instagram.com',
        'instagr.am',
        // LinkedIn
        'linkedin.com',
        'co.linkedin.com',
        // Twitter / X
        'twitter.com',
        'x.com',
        't.co',
        // YouTube
        'youtube.com',
        'youtu.be',
        // TikTok
        'tiktok.com',
        // WhatsApp Business
        'wa.me',
        'whatsapp.com',
        // YouTube Shorts etc
    ];

    /**
     * Devuelve true si la URL apunta a una red social conocida.
     *
     * Acepta URL con o sin esquema (http/https).
     *
     * @param string|null $url
     */
    public function isSocialNetworkUrl(?string $url): bool
    {
        if (! $url) return false;
        $host = $this->extractHost($url);
        if (! $host) return false;
        foreach (self::SOCIAL_DOMAINS as $sd) {
            $sd = strtolower($sd);
            // Match exacto o match por subdominio (.facebook.com también es FB)
            if ($host === $sd || str_ends_with($host, '.' . $sd)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Devuelve el dominio (host) de una URL, o null si no se puede parsear.
     *
     * @param string|null $url
     * @return string|null Host normalizado a lowercase, sin www
     */
    public function extractHost(?string $url): ?string
    {
        if (! $url) return null;
        $url = trim($url);
        if ($url === '') return null;

        // Si no tiene esquema, agregárselo
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }

        $parts = parse_url($url);
        if (! isset($parts['host'])) return null;
        $host = strtolower($parts['host']);

        // Quitar www. al inicio
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Categoriza una URL: devuelve 'domain', 'social', o null si está vacía.
     *
     * - 'domain': URL de un sitio web propio (no es red social)
     * - 'social': URL de una red social (FB, IG, LI, Twitter, etc)
     * - null: URL vacía o malformada
     *
     * @param string|null $url
     * @return string|null
     */
    public function categorizeUrl(?string $url): ?string
    {
        if (! $url) return null;
        if ($this->isSocialNetworkUrl($url)) {
            return 'social';
        }
        if ($this->extractHost($url)) {
            return 'domain';
        }
        return null;
    }
}
