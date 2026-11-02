import { onMounted, onBeforeUnmount, type Ref, isRef, ref } from 'vue';

export interface ScrollRevealOptions {
    /** IntersectionObserver threshold (0–1). Default 0.08 */
    threshold?: number;
    /** Stagger delay between sibling elements in ms. Default 80 */
    stagger?: number;
    /** Whether to replay on scroll back in. Default false (animate once). */
    once?: boolean;
}

/**
 * Scroll-triggered reveal animation for sections and elements.
 *
 * Usage:
 *   const sectionRefs = ref<HTMLElement[]>([]);
 *   useScrollReveal(sectionRefs);
 *   <section v-for="..." ref="sectionRefs"> ... </section>
 *
 * Elements get `scroll-reveal` and `scroll-revealed` CSS classes.
 * CSS handles the actual animation — see the companion styles below.
 */
export function useScrollReveal(
    targets: Ref<HTMLElement[]> | Ref<HTMLElement | undefined>,
    options: ScrollRevealOptions = {},
): void {
    const { threshold = 0.08, stagger = 80, once = true } = options;
    let observer: IntersectionObserver | undefined;

    onMounted(() => {
        // Bail out for reduced-motion or missing API
        if (
            typeof window === 'undefined' ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches ||
            !('IntersectionObserver' in window)
        ) {
            return;
        }

        observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry, i) => {
                    if (entry.isIntersecting) {
                        const el = entry.target as HTMLElement;
                        // Stagger based on how many siblings are revealing in the same tick
                        el.style.transitionDelay = `${i * stagger}ms`;
                        el.classList.add('scroll-revealed');

                        if (once) observer?.unobserve(el);
                    } else if (!once) {
                        (entry.target as HTMLElement).classList.remove('scroll-revealed');
                        (entry.target as HTMLElement).style.transitionDelay = '';
                    }
                });
            },
            { threshold },
        );

        const raw = isRef(targets) ? targets.value : targets;
        const elements = Array.isArray(raw) ? raw : raw ? [raw] : [];

        elements.forEach((el) => {
            if (!el) return;
            // Only add reveal class if element is below the fold
            if (el.getBoundingClientRect().top > window.innerHeight * 0.85) {
                el.classList.add('scroll-reveal');
            }
            observer?.observe(el);
        });
    });

    onBeforeUnmount(() => observer?.disconnect());
}
