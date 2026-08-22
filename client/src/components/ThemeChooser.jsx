import React, { useState, useRef, useEffect } from 'react';
import { useTheme } from '../context/ThemeContext';
import { Sun, Moon, Check } from 'lucide-react';

export const ThemeChooser = () => {
  const { currentTheme, changeTheme, lang } = useTheme();
  const t = (en, am) => (lang === 'EN' ? en : am);
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef(null);

  // Close dropdown on outside click
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleSelectTheme = (themeId) => {
    changeTheme(themeId);
    setIsOpen(false);
  };

  const isLight = currentTheme === 'light';

  return (
    <div className="relative" ref={dropdownRef}>
      {/* Theme Trigger Button */}
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="px-3 py-1.5 rounded-xl bg-slate-800/80 hover:bg-slate-700/80 border border-slate-700/60 text-slate-200 text-xs font-bold flex items-center gap-2 transition-all shadow-md cursor-pointer"
        title={t('Choose Theme', 'ገጽታ ይምረጡ')}
      >
        {isLight ? (
          <Sun className="w-4 h-4 text-amber-400" />
        ) : (
          <Moon className="w-4 h-4 text-sky-400" />
        )}
        <span>{t('Theme', 'ገጽታ')}</span>
      </button>

      {/* Dropdown Options */}
      {isOpen && (
        <div className="absolute right-0 mt-2 w-48 rounded-2xl bg-slate-900 border border-slate-700 shadow-2xl p-1.5 space-y-1 z-50 animate-fade-in">
          <button
            onClick={() => handleSelectTheme('light')}
            className={`w-full px-3.5 py-2 rounded-xl text-left text-xs font-bold flex items-center justify-between transition-all cursor-pointer ${
              isLight
                ? 'bg-slate-800 text-emerald-400 border border-slate-700/60'
                : 'text-slate-300 hover:bg-slate-800/60 hover:text-slate-100'
            }`}
          >
            <div className="flex items-center gap-2.5">
              <Sun className="w-4 h-4 text-amber-400" />
              <span>{t('Light Theme', 'ብሩህ ገጽታ')}</span>
            </div>
            {isLight && <Check className="w-3.5 h-3.5" />}
          </button>

          <button
            onClick={() => handleSelectTheme('default')}
            className={`w-full px-3.5 py-2 rounded-xl text-left text-xs font-bold flex items-center justify-between transition-all cursor-pointer ${
              !isLight
                ? 'bg-slate-800 text-emerald-400 border border-slate-700/60'
                : 'text-slate-300 hover:bg-slate-800/60 hover:text-slate-100'
            }`}
          >
            <div className="flex items-center gap-2.5">
              <Moon className="w-4 h-4 text-sky-400" />
              <span>{t('Dark Theme', 'ጨለማ ገጽታ')}</span>
            </div>
            {!isLight && <Check className="w-3.5 h-3.5" />}
          </button>
        </div>
      )}
    </div>
  );
};
