import React, { createContext, useContext, useState, useEffect } from 'react';

const ThemeContext = createContext();

export const THEMES = [
  {
    id: 'default',
    name: 'Midnight Emerald',
    description: 'Classic Ethiopian Dark with Emerald & Yellow accents',
    bgClass: 'bg-[#0B0F19]',
    panelClass: 'bg-[#151C2C]/80 border-slate-800',
    cardClass: 'bg-[#1E293B]/60 border-slate-800/80',
    primaryColor: '#10B981', // emerald
    secondaryColor: '#EAB308', // yellow
    swatch: ['#0B0F19', '#10B981', '#EAB308']
  },
  {
    id: 'light',
    name: 'Comfortable Light',
    description: 'Clean off-white background with soft emerald accents',
    bgClass: 'bg-[#F8FAFC]',
    panelClass: 'bg-white/90 border-slate-200/80 shadow-md',
    cardClass: 'bg-white border-slate-200 shadow-sm',
    primaryColor: '#10B981', // emerald
    secondaryColor: '#475569', // slate grey
    swatch: ['#FFFFFF', '#10B981', '#475569']
  },
  {
    id: 'ocean',
    name: 'Ocean Sapphire',
    description: 'Deep Blue Navy with vibrant Cyan & Sky highlights',
    bgClass: 'bg-[#091322]',
    panelClass: 'bg-[#0F213A]/80 border-blue-900/40',
    cardClass: 'bg-[#162C4E]/60 border-blue-800/40',
    primaryColor: '#06B6D4', // cyan
    secondaryColor: '#38BDF8', // sky
    swatch: ['#091322', '#06B6D4', '#38BDF8']
  },
  {
    id: 'purple',
    name: 'Royal Amethyst',
    description: 'Deep Indigo & Violet with Glowing Magenta accents',
    bgClass: 'bg-[#120B24]',
    panelClass: 'bg-[#1D1336]/80 border-purple-900/40',
    cardClass: 'bg-[#291B4C]/60 border-purple-800/40',
    primaryColor: '#A855F7', // purple
    secondaryColor: '#EC4899', // pink
    swatch: ['#120B24', '#A855F7', '#EC4899']
  },
  {
    id: 'amber',
    name: 'Sunset Gold',
    description: 'Rich Charcoal & Obsidian with Golden Amber warmth',
    bgClass: 'bg-[#14100B]',
    panelClass: 'bg-[#221B13]/80 border-amber-900/40',
    cardClass: 'bg-[#31271C]/60 border-amber-800/40',
    primaryColor: '#F59E0B', // amber
    secondaryColor: '#F97316', // orange
    swatch: ['#14100B', '#F59E0B', '#F97316']
  },
  {
    id: 'emerald',
    name: 'Lush Forest',
    description: 'Deep Forest Charcoal with Mint & Lime highlights',
    bgClass: 'bg-[#081711]',
    panelClass: 'bg-[#0E271D]/80 border-emerald-900/40',
    cardClass: 'bg-[#153A2B]/60 border-emerald-800/40',
    primaryColor: '#10B981', // emerald
    secondaryColor: '#84CC16', // lime
    swatch: ['#081711', '#10B981', '#84CC16']
  },
  {
    id: 'rose',
    name: 'Crimson Velvet',
    description: 'Velvet Wine with Rich Rose & Coral glows',
    bgClass: 'bg-[#1A0B13]',
    panelClass: 'bg-[#2B1220]/80 border-rose-900/40',
    cardClass: 'bg-[#3D1A2E]/60 border-rose-800/40',
    primaryColor: '#F43F5E', // rose
    secondaryColor: '#FB7185', // rose light
    swatch: ['#1A0B13', '#F43F5E', '#FB7185']
  }
];

export const ThemeProvider = ({ children }) => {
  const [currentTheme, setCurrentTheme] = useState(() => {
    const saved = localStorage.getItem('smart_dube_theme');
    return saved && THEMES.find(t => t.id === saved) ? saved : 'default';
  });

  const activeTheme = THEMES.find(t => t.id === currentTheme) || THEMES[0];

  useEffect(() => {
    localStorage.setItem('smart_dube_theme', currentTheme);
    document.documentElement.setAttribute('data-theme', currentTheme);
  }, [currentTheme]);

  const changeTheme = (themeId) => {
    if (THEMES.some(t => t.id === themeId)) {
      setCurrentTheme(themeId);
    }
  };

  const [lang, setLang] = useState(() => {
    return localStorage.getItem('smart_dube_lang') || 'EN';
  });

  useEffect(() => {
    localStorage.setItem('smart_dube_lang', lang);
  }, [lang]);

  return (
    <ThemeContext.Provider value={{ currentTheme, activeTheme, changeTheme, themes: THEMES, lang, setLang }}>
      {children}
    </ThemeContext.Provider>
  );
};

export const useTheme = () => useContext(ThemeContext);
