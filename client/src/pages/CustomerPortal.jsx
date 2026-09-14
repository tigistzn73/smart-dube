import React, { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import { useTheme } from '../context/ThemeContext';
import { PaymentModal } from '../components/PaymentModal';
import { ReceiptModal } from '../components/ReceiptModal';
import {
  Wallet,
  CreditCard,
  Calendar,
  Store,
  CheckCircle2,
  Clock,
  ShieldCheck,
  Receipt,
  X,
  Bell,
  AlertCircle,
  PanelLeftClose,
  PanelLeftOpen,
  Menu
} from 'lucide-react';

export const CustomerPortal = () => {
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const { lang } = useTheme();
  const t = (en, am) => (lang === 'EN' ? en : am);
  const [selectedTxForPayment, setSelectedTxForPayment] = useState(null);
  const [selectedReceipt, setSelectedReceipt] = useState(null);
  const [activeTab, setActiveTab] = useState('DASHBOARD');
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);

  // Installment Scheduler State
  const [scheduleModalOpen, setScheduleModalOpen] = useState(false);
  const [frequency, setFrequency] = useState('WEEKLY');
  const [numInstallments, setNumInstallments] = useState(2);
  const [scheduleResult, setScheduleResult] = useState(null);
  const [selectedScheduleMerchant, setSelectedScheduleMerchant] = useState('ALL');
  const [selectedScheduleViewMerchant, setSelectedScheduleViewMerchant] = useState('ALL');

  // Alerts Popover Modal State
  const [alertsOpen, setAlertsOpen] = useState(false);
  const [dismissedAlertIds, setDismissedAlertIds] = useState([]);
  const [readAlertIds, setReadAlertIds] = useState([]);

  // Listen for mobile sidebar toggle and direct tab switch from Navbar
  useEffect(() => {
    const handleToggle = () => setMobileSidebarOpen(prev => !prev);
    const handleSwitch = (e) => {
      if (e.detail?.tab) setActiveTab(e.detail.tab);
    };
    window.addEventListener('toggle-mobile-sidebar', handleToggle);
    window.addEventListener('switch-tab', handleSwitch);
    return () => {
      window.removeEventListener('toggle-mobile-sidebar', handleToggle);
      window.removeEventListener('switch-tab', handleSwitch);
    };
  }, []);

  const token = localStorage.getItem('smart_dube_token');

  useEffect(() => {
    fetchCustomerDashboard();
  }, []);

  const fetchCustomerDashboard = async () => {
    setLoading(true);
    try {
      const res = await fetch('/api/customer/dashboard', {
        headers: { Authorization: `Bearer ${token}` }
      });
      const resData = await res.json();
      setData(resData);
    } catch (err) {
      console.error('Customer dashboard error:', err);
    } finally {
      setLoading(false);
    }
  };

  const [applyingSchedule, setApplyingSchedule] = useState(false);

  const handleGenerateSchedule = async () => {
    let targetBalance = data?.summary?.totalBalance || 0;
    const merchantIdForSchedule = selectedScheduleMerchant !== 'ALL' ? Number(selectedScheduleMerchant) : null;
    if (selectedScheduleMerchant !== 'ALL') {
      const p = profiles.find(pr => String(pr.merchant_id) === String(selectedScheduleMerchant));
      if (p) targetBalance = p.current_balance;
    }
    if (!targetBalance) return;
    try {
      const res = await fetch('/api/customer/schedule', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`
        },
        body: JSON.stringify({
          totalAmount: targetBalance,
          frequency,
          numInstallments,
          merchantId: merchantIdForSchedule
        })
      });
      const sData = await res.json();
      setScheduleResult(sData);
    } catch (err) {
      alert(err.message);
    }
  };

  const handleApplySchedule = async () => {
    let targetBalance = data?.summary?.totalBalance || 0;
    const merchantIdForSchedule = selectedScheduleMerchant !== 'ALL' ? Number(selectedScheduleMerchant) : null;
    if (selectedScheduleMerchant !== 'ALL') {
      const p = profiles.find(pr => String(pr.merchant_id) === String(selectedScheduleMerchant));
      if (p) targetBalance = p.current_balance;
    }
    if (!targetBalance) return;
    try {
      setApplyingSchedule(true);
      const res = await fetch('/api/customer/schedule/apply', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`
        },
        body: JSON.stringify({
          totalAmount: targetBalance,
          frequency,
          numInstallments,
          merchantId: merchantIdForSchedule
        })
      });
      const sData = await res.json();
      if (!res.ok) throw new Error(sData.error || 'Failed to apply schedule.');
      alert('✓ Flexible Repayment Schedule applied successfully!');
      setScheduleModalOpen(false);
      setScheduleResult(null);
      fetchCustomerDashboard();
    } catch (err) {
      alert(err.message);
    } finally {
      setApplyingSchedule(false);
    }
  };

  const openScheduleModal = () => {
    setNumInstallments(2);
    setScheduleResult(null);
    setScheduleModalOpen(true);
  };

  const notifications = data?.notifications || [];
  const visibleNotifications = notifications.filter(n => !dismissedAlertIds.includes(n.id));
  const unreadCount = visibleNotifications.filter(n => !readAlertIds.includes(n.id)).length;

  const dismissAlert = (id) => setDismissedAlertIds(prev => [...prev, id]);

  const openAlerts = () => {
    setAlertsOpen(true);
    // Mark all currently visible notifications as read
    setReadAlertIds(prev => {
      const newIds = visibleNotifications.map(n => n.id).filter(id => !prev.includes(id));
      return [...prev, ...newIds];
    });
  };

  // Sync unread alerts count to Navbar
  useEffect(() => {
    window.dispatchEvent(new CustomEvent('update-unread-alerts', { detail: { count: unreadCount } }));
  }, [unreadCount]);

  // Listen for open event from Navbar
  useEffect(() => {
    const handleOpen = () => openAlerts();
    window.addEventListener('open-inbox-alerts', handleOpen);
    return () => window.removeEventListener('open-inbox-alerts', handleOpen);
  }, [visibleNotifications]);

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[60vh]">
        <div className="w-10 h-10 border-4 border-emerald-500/20 border-t-emerald-500 rounded-full animate-spin"></div>
      </div>
    );
  }

  const summary = data?.summary || { totalBalance: 0, totalCreditLimit: 0, availableCredit: 0 };
  const transactions = data?.transactions || [];
  const pendingTransactions = transactions.filter(tx => tx.status !== 'SETTLED');
  const repayments = data?.repayments || [];
  const profiles = data?.profiles || [];

  const chartWidth = 500;
  const chartHeight = 150;

  const getLast7DaysDube = () => {
    const days = [];
    const salesByDay = {};

    for (let i = 6; i >= 0; i--) {
      const d = new Date();
      d.setDate(d.getDate() - i);
      const dateStr = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
      days.push(dateStr);
      salesByDay[dateStr] = 0;
    }

    const txs = transactions || [];
    txs.forEach(tx => {
      const txDateStr = new Date(tx.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
      if (salesByDay[txDateStr] !== undefined) {
        salesByDay[txDateStr] += parseFloat(tx.total_amount || 0);
      }
    });

    return days.map(day => ({
      day,
      sales: salesByDay[day]
    }));
  };

  const points = getLast7DaysDube();
  const maxSale = Math.max(...points.map(p => p.sales), 1);

  const getCoordinates = () => {
    const paddingX = 40;
    const paddingY = 20;
    const width = chartWidth - paddingX * 2;
    const height = chartHeight - paddingY * 2;

    return points.map((p, index) => {
      const x = paddingX + (index / (points.length - 1)) * width;
      const y = paddingY + height - (p.sales / maxSale) * height;
      return { x, y, day: p.day, sales: p.sales };
    });
  };

  const chartPoints = getCoordinates();

  const pathD = chartPoints.reduce((acc, p, i) => {
    return i === 0 ? `M ${p.x} ${p.y}` : `${acc} L ${p.x} ${p.y}`;
  }, '');

  const areaD = pathD ? `${pathD} L ${chartPoints[chartPoints.length - 1].x} ${chartHeight - 20} L ${chartPoints[0].x} ${chartHeight - 20} Z` : '';

  return (
    <div className="flex-1 min-h-0 flex flex-col">
      <div className="flex flex-row gap-1.5 md:gap-3 items-start flex-1 min-h-0">
        {/* MOBILE SIDEBAR DRAWER (FOR PHONES) */}
        {mobileSidebarOpen && (
          <div className="fixed inset-0 z-50 flex md:hidden">
            <div
              className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm transition-opacity"
              onClick={() => setMobileSidebarOpen(false)}
            />
            <aside className="relative w-72 max-w-[85vw] bg-slate-900 border-r border-slate-800 p-4 flex flex-col justify-between h-full z-50 shadow-2xl overflow-y-auto">
              <div className="space-y-4">
                <div className="flex items-center justify-between pb-3 border-b border-slate-800">
                  <div className="flex items-center gap-2">
                    <Wallet className="w-4 h-4 text-emerald-400" />
                    <span className="font-extrabold text-sm text-slate-100">{t('Customer Menu', 'የደንበኛ ምናሌ')}</span>
                  </div>
                  <button
                    onClick={() => setMobileSidebarOpen(false)}
                    className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 cursor-pointer"
                  >
                    <X className="w-5 h-5" />
                  </button>
                </div>

                <nav className="flex flex-col gap-2">
                  {[
                    { id: 'DASHBOARD', name: t('Dashboard Home', 'ዳሽቦርድ መነሻ'), icon: Wallet },
                    { id: 'MERCHANTS', name: t('Linked Merchants', 'የተገናኙ ነጋዴዎች'), icon: Store, count: profiles.length },
                    { id: 'TRANSACTIONS', name: t('Pending Dube Receipts', 'ያልተከፈሉ ደረሰኞች'), icon: Receipt, count: pendingTransactions.length },
                    { id: 'REPAYMENTS', name: t('Settlement History', 'የክፍያ ታሪክ'), icon: CheckCircle2 }
                  ].map(item => {
                    const isActive = activeTab === item.id;
                    const Icon = item.icon;
                    return (
                      <button
                        key={item.id}
                        onClick={() => {
                          setActiveTab(item.id);
                          setMobileSidebarOpen(false);
                        }}
                        className={`w-full p-3 rounded-xl text-xs font-bold transition-all flex items-center justify-between border cursor-pointer ${
                          isActive
                            ? 'bg-slate-800 text-emerald-400 border-slate-700 shadow-md'
                            : 'text-slate-400 hover:text-slate-200 bg-transparent border-transparent hover:bg-slate-850'
                        }`}
                      >
                        <div className="flex items-center gap-3">
                          <Icon className="w-4 h-4" />
                          <span>{item.name}</span>
                        </div>
                        {item.count !== undefined && (
                          <span className={`px-2 py-0.5 rounded-full text-[10px] font-mono font-bold ${
                            isActive ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-950 text-slate-400'
                          }`}>
                            {item.count}
                          </span>
                        )}
                      </button>
                    );
                  })}
                </nav>
              </div>

              <div className="pt-4 border-t border-slate-800 text-[10px] text-slate-500 text-center">
                Smart Dube Mobile • Ethiopian BNPL
              </div>
            </aside>
          </div>
        )}

        {/* LEFT SIDEBAR NAVIGATION (DESKTOP ONLY) */}
        <aside className={`hidden md:flex ${sidebarCollapsed ? 'w-[68px]' : 'w-60'} flex-shrink-0 glass-panel rounded-2xl p-2 md:p-3 flex-col justify-between border border-slate-800 sticky top-[52px] h-[calc(100vh-56px)] overflow-y-auto transition-all duration-300`}>
          <div className="space-y-3 md:space-y-4 w-full">
            {/* Header Toggle */}
            <div className={`flex items-center ${sidebarCollapsed ? 'justify-center' : 'justify-between'} pb-2 border-b border-slate-850`}>
              <span className={`hidden ${sidebarCollapsed ? '' : 'md:inline'} text-[10px] font-bold text-slate-500 uppercase tracking-wider font-mono`}>Nav</span>
              <button
                type="button"
                onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-all cursor-pointer"
                title={sidebarCollapsed ? 'Open sidebar' : 'Close sidebar'}
              >
                {sidebarCollapsed ? <PanelLeftOpen className="w-4 h-4" /> : <PanelLeftClose className="w-4 h-4" />}
              </button>
            </div>

            <nav className="flex flex-col gap-2 md:gap-1.5 w-full">
              {[
                { id: 'DASHBOARD', name: t('Dashboard Home', 'ዳሽቦርድ መነሻ'), icon: Wallet },
                { id: 'MERCHANTS', name: t('Linked Merchants', 'የተገናኙ ነጋዴዎች'), icon: Store, count: profiles.length },
                { id: 'TRANSACTIONS', name: t('Pending Dube Receipts', 'ያልተከፈሉ ደረሰኞች'), icon: Receipt, count: pendingTransactions.length },
                { id: 'REPAYMENTS', name: t('Settlement History', 'የክፍያ ታሪክ'), icon: CheckCircle2 }
              ].map(item => {
                const isActive = activeTab === item.id;
                const Icon = item.icon;
                return (
                  <button
                    key={item.id}
                    onClick={() => setActiveTab(item.id)}
                    className={`w-full p-2 md:px-3 md:py-2.5 ${sidebarCollapsed ? 'md:justify-center' : 'justify-center md:justify-between'} rounded-xl text-xs font-bold transition-all flex items-center border cursor-pointer ${
                      isActive
                        ? 'bg-slate-800/80 text-emerald-400 border-slate-700/60 shadow-md'
                        : 'text-slate-400 hover:text-slate-200 bg-transparent border-transparent hover:bg-slate-900/40'
                    }`}
                    title={item.name}
                  >
                    <div className="flex items-center gap-2.5">
                      <Icon className="w-4 h-4 shrink-0" />
                      <span className={`hidden ${sidebarCollapsed ? '' : 'md:inline'} whitespace-nowrap`}>{item.name}</span>
                    </div>
                    {!sidebarCollapsed && item.count !== undefined && (
                      <span className={`hidden md:inline-block px-2 py-0.5 rounded-full text-[10px] font-mono font-bold ${
                        isActive ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-950/60 text-slate-500'
                      }`}>
                        {item.count}
                      </span>
                    )}
                  </button>
                );
              })}
            </nav>
          </div>
        </aside>

        {/* RIGHT MAIN CONTENT AREA */}
        <div className="flex-1 w-full space-y-6 lg:max-h-[calc(100vh-160px)] lg:overflow-y-auto pr-2 pb-20">
          {/* TAB 1: DASHBOARD HOME */}
          {activeTab === 'DASHBOARD' && (
            <div className="space-y-6">
              {/* Balance Summary Header */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                {/* Card 1: Outstanding Debt */}
                <div className="glass-panel p-3.5 md:p-4 rounded-xl border border-slate-800/80 relative overflow-hidden shadow-sm">
                  <div className="flex justify-between items-start mb-1.5">
                    <div>
                      <p className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">{t('Total Dube Debt Balance', 'አጠቃላይ የዱቤ ብድር ቀሪ ሂሳብ')}</p>
                      <h2 className="text-xl md:text-2xl font-black text-amber-400 mt-0.5">{summary.totalBalance.toFixed(2)} ETB</h2>
                    </div>
                    <div className="p-2 bg-amber-500/10 text-amber-400 rounded-lg border border-amber-500/20 shrink-0">
                      <Wallet className="w-4 h-4" />
                    </div>
                  </div>
                  <p className="text-[11px] text-slate-400">{t(`Across ${summary.activeAccountsCount || 0} neighborhood merchant accounts`, `በ${summary.activeAccountsCount || 0} የነጋዴ አካውንቶች ውስጥ`)}</p>
                </div>

                {/* Card 2: Total Credit Limit */}
                <div className="glass-panel p-3.5 md:p-4 rounded-xl border border-slate-800/80 relative overflow-hidden shadow-sm">
                  <div className="flex justify-between items-start mb-1.5">
                    <div>
                      <p className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">{t('Approved Credit Limit', 'የተፈቀደ የዱቤ መጠን')}</p>
                      <h2 className="text-xl md:text-2xl font-black text-emerald-400 mt-0.5">{summary.totalCreditLimit.toFixed(2)} ETB</h2>
                    </div>
                    <div className="p-2 bg-emerald-500/10 text-emerald-400 rounded-lg border border-emerald-500/20 shrink-0">
                      <ShieldCheck className="w-4 h-4" />
                    </div>
                  </div>
                  <p className="text-[11px] text-slate-400">{t(`Available: ${summary.availableCredit.toFixed(2)} ETB remaining`, `የቀረ ነጻ ዱቤ፡ ${summary.availableCredit.toFixed(2)} ETB`)}</p>
                </div>
              </div>

              {/* Weekly Dube Credit Utilization Trend Graph */}
              <div className="glass-card p-5 rounded-2xl border border-slate-800/80 space-y-4">
                <div className="flex justify-between items-center">
                  <h4 className="text-xs font-bold text-slate-400 uppercase tracking-wider font-mono">
                    {t('Your Weekly Dube Credit Purchase Trend', 'የእርስዎ ሳምንታዊ የዱቤ አጠቃቀም እንቅስቃሴ')}
                  </h4>
                  <span className="text-[10px] font-bold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded-full">
                    {t('Last 7 Days', 'ያለፉት 7 ቀናት')}
                  </span>
                </div>
                <div className="relative pt-4">
                  <svg viewBox={`0 0 ${chartWidth} ${chartHeight}`} className="w-full h-32 overflow-visible">
                    <defs>
                      <linearGradient id="chart-gradient-cust" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#10B981" stopOpacity="0.25"/>
                        <stop offset="100%" stopColor="#10B981" stopOpacity="0"/>
                      </linearGradient>
                    </defs>

                    {/* Horizontal Gridlines */}
                    {[0.25, 0.5, 0.75].map((ratio, idx) => {
                      const y = 20 + ratio * (chartHeight - 40);
                      return (
                        <line
                          key={idx}
                          x1="0"
                          y1={y}
                          x2={chartWidth}
                          y2={y}
                          stroke="#334155"
                          strokeDasharray="4 4"
                          strokeWidth="0.5"
                        />
                      );
                    })}

                    {/* Area path */}
                    {areaD && <path d={areaD} fill="url(#chart-gradient-cust)" />}

                    {/* Line path */}
                    {pathD && (
                      <path
                        d={pathD}
                        fill="none"
                        stroke="#10B981"
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        className="drop-shadow-[0_0_6px_rgba(16,185,129,0.3)]"
                      />
                    )}

                    {/* Chart Points & Labels */}
                    {chartPoints.map((p, idx) => (
                      <g key={idx}>
                        <circle
                          cx={p.x}
                          cy={p.y}
                          r="4"
                          className="fill-emerald-400 stroke-slate-900 stroke-2 hover:r-5 transition-all"
                        />
                        {p.sales > 0 && (
                          <text
                            x={p.x}
                            y={p.y - 10}
                            textAnchor="middle"
                            className="text-[9px] fill-emerald-300 font-mono font-bold"
                          >
                            {p.sales.toFixed(0)}
                          </text>
                        )}
                        <text
                          x={p.x}
                          y={chartHeight - 2}
                          textAnchor="middle"
                          className="text-[9px] fill-slate-500 font-mono font-semibold"
                        >
                          {p.day}
                        </text>
                      </g>
                    ))}
                  </svg>
                </div>
              </div>

              {/* Quick Summary text info panel */}
              <div className="glass-panel p-3.5 md:p-4 rounded-xl border border-slate-800 text-xs text-slate-400 space-y-1.5">
                <p className="font-bold text-slate-200">{t('Welcome to your Smart Dube Customer Portal!', 'እንኳን ወደ ስማርት ዱቤ ደንበኛ ገጽ በደህና መጡ!')}</p>
                <p>
                  {t('Use the left sidebar navigation to view details of your neighborhood merchant ledger accounts, pay off pending credit receipts, and inspect your past payments.', 'የግራ የጎን ምናሌን በመጠቀም የነጋዴዎችዎን አካውንት መረጃ ማየት፣ ያልተከፈሉ ደረሰኞች መክፈል፣ እና ያለፉ ክፍያዎችን መከታተል ይችላሉ።')}
                </p>
              </div>
            </div>
          )}

          {/* TAB 2: NEIGHBORHOOD MERCHANTS */}
          {activeTab === 'MERCHANTS' && (
            <div className="space-y-4">
              <div className="flex justify-between items-center mb-1">
                <h3 className="text-sm font-bold text-slate-300 uppercase tracking-wider">
                  {t('Your Linked Dube Merchant Ledgers', 'የተገናኙ የነጋዴ ዱቤ አካውንቶች')}
                </h3>
                <span className="text-xs font-semibold text-slate-400 font-mono">
                  {profiles.length} {t('Active Ledgers', 'ገባሪ አካውንቶች')}
                </span>
              </div>
              {profiles.length === 0 ? (
                <div className="glass-panel p-8 rounded-2xl border border-slate-800 text-center space-y-3">
                  <div className="w-12 h-12 rounded-2xl bg-slate-800/80 text-slate-400 border border-slate-700 flex items-center justify-center mx-auto">
                    <Store className="w-6 h-6 text-amber-400" />
                  </div>
                  <h4 className="font-extrabold text-sm text-slate-200">
                    {t('No Linked Merchants Yet', 'ምንም የተገናኙ ነጋዴዎች የሉም')}
                  </h4>
                  <p className="text-xs text-slate-400 max-w-md mx-auto">
                    {t(
                      'You do not have any active merchant credit ledgers yet. When a neighborhood store (like Arada Supermarket or Zemero) registers or extends credit to your phone number, your store ledger and approved credit limit will appear here.',
                      'እስካሁን ከየትኛውም የነጋዴ ዱቤ አካውንት ጋር አልተገናኙም። የሰፈር ነጋዴዎች በስልክ ቁጥርዎ ወይም በፋይዳ መታወቂያዎ ዱቤ ሲፈቅዱልዎት፣ የብድር ገደብዎ እና አካውንትዎ እዚህ ይታያል።'
                    )}
                  </p>
                </div>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {profiles.map(p => {
                    const creditLimit = parseFloat(p.credit_limit || 0);
                    const currentBalance = parseFloat(p.current_balance || 0);
                    const availableLimit = Math.max(0, creditLimit - currentBalance);
                    const utilization = creditLimit > 0 ? Math.min(100, Math.round((currentBalance / creditLimit) * 100)) : 0;

                    return (
                      <div key={p.id} className="glass-card p-4 sm:p-5 rounded-2xl border border-slate-800 space-y-3.5 hover:border-slate-700 transition-all shadow-md">
                        {/* Top Row: Store Name & Address */}
                        <div className="flex justify-between items-start gap-3">
                          <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center font-bold shrink-0">
                              <Store className="w-5 h-5" />
                            </div>
                            <div>
                              <h4 className="font-bold text-sm text-slate-100">{p.store_name}</h4>
                              <p className="text-xs text-slate-400 mt-0.5">{p.store_address}</p>
                            </div>
                          </div>
                          <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold font-mono ${
                            p.status === 'ACTIVE'
                              ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'
                              : 'bg-red-500/20 text-red-400 border border-red-500/30'
                          }`}>
                            {p.status || 'ACTIVE'}
                          </span>
                        </div>

                        {/* Middle Stats Grid: Approved Limit, Account Debt, Available Limit */}
                        <div className="grid grid-cols-3 gap-2 p-3 rounded-xl bg-slate-950/60 border border-slate-850">
                          <div>
                            <p className="text-[10px] text-slate-400 font-semibold">{t('Approved Limit:', 'የተፈቀደ ገደብ፦')}</p>
                            <p className="font-extrabold text-sky-400 text-xs sm:text-sm font-mono mt-0.5">
                              {creditLimit.toFixed(2)} <span className="text-[9px] font-sans">ETB</span>
                            </p>
                          </div>
                          <div className="text-center border-x border-slate-800/80 px-1">
                            <p className="text-[10px] text-slate-400 font-semibold">{t('Account Debt:', 'የአካውንት እዳ፦')}</p>
                            <p className="font-extrabold text-amber-400 text-xs sm:text-sm font-mono mt-0.5">
                              {currentBalance.toFixed(2)} <span className="text-[9px] font-sans">ETB</span>
                            </p>
                          </div>
                          <div className="text-right">
                            <p className="text-[10px] text-slate-400 font-semibold">{t('Available Limit:', 'ቀሪ ገደብ፦')}</p>
                            <p className="font-extrabold text-emerald-400 text-xs sm:text-sm font-mono mt-0.5">
                              {availableLimit.toFixed(2)} <span className="text-[9px] font-sans">ETB</span>
                            </p>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          )}

          {/* TAB 3: PENDING RECEIPTS */}
          {activeTab === 'TRANSACTIONS' && (
            <div className="space-y-6">
              {/* Salary Cycle Repayment Scheduler Trigger & Active Plan Card */}
              <div className="glass-panel p-6 rounded-2xl border border-slate-800 flex flex-col justify-between">
                <div>
                  <div className="flex items-center justify-between">
                    <h4 className="text-sm font-bold text-slate-100 flex items-center gap-2">
                      <Calendar className="w-4 h-4 text-sky-400" />
                      {t('Salary Repayment Schedule', 'የደመወዝ ክፍያ የጊዜ ሰሌዳ')}
                    </h4>
                    {data?.activeSchedule && summary.totalBalance > 0 && data.activeSchedule.installments?.some(i => i.status !== 'PAID') && (
                      <span className="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        {t('ACTIVE PLAN', 'ገባሪ እቅድ')}
                      </span>
                    )}
                  </div>
                  <p className="text-xs text-slate-400 mt-1">
                    {t('Split Dube balances into weekly or monthly salary installments per store', 'የዱቤ እዳዎችን ለእያንዳንዱ ሱቅ ወደ ሳምንታዊ ወይም ወርሃዊ የደመወዝ ክፍሎች ይከፋፍሉ')}
                  </p>

                  {/* Store Schedule Switcher Tabs */}
                  {profiles.length > 1 && (
                    <div className="flex items-center gap-1.5 overflow-x-auto pb-1 mt-3">
                      {profiles.map(p => {
                        const isSelected = (selectedScheduleViewMerchant === String(p.merchant_id)) ||
                          (!selectedScheduleViewMerchant || selectedScheduleViewMerchant === 'ALL' ? p.id === profiles[0].id : false);

                        return (
                          <button
                            key={p.id}
                            onClick={() => {
                              setSelectedScheduleViewMerchant(String(p.merchant_id));
                              setSelectedScheduleMerchant(String(p.merchant_id));
                            }}
                            className={`px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-1.5 shrink-0 ${
                              isSelected
                                ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/20 ring-1 ring-emerald-400'
                                : 'bg-slate-900/90 text-slate-300 hover:text-white hover:bg-slate-800 border border-slate-800'
                            }`}
                          >
                            <Store className="w-3.5 h-3.5" />
                            <span>{p.store_name}</span>
                            {p.current_balance > 0 && (
                              <span className="text-[10px] px-1.5 py-0.2 rounded bg-black/30 font-mono text-amber-300">
                                {p.current_balance.toFixed(0)} ETB
                              </span>
                            )}
                          </button>
                        );
                      })}
                    </div>
                  )}
                </div>

                {/* Resolve current active schedule for the chosen store */}
                {(() => {
                  const allActiveSchedules = data?.activeSchedules || (data?.activeSchedule ? [data.activeSchedule] : []);
                  const activeMerchantId = (selectedScheduleViewMerchant && selectedScheduleViewMerchant !== 'ALL')
                    ? selectedScheduleViewMerchant
                    : (profiles[0] ? String(profiles[0].merchant_id) : '');

                  const profileForStore = profiles.find(p => String(p.merchant_id) === activeMerchantId) || profiles[0];
                  const currentViewSchedule = allActiveSchedules.find(s =>
                    (profileForStore && String(s.merchant_id) === String(profileForStore.merchant_id)) ||
                    (profileForStore && s.customer_id === profileForStore.id)
                  );

                  const hasUnpaidInsts = currentViewSchedule && currentViewSchedule.installments?.some(i => i.status !== 'PAID');

                  if (currentViewSchedule && hasUnpaidInsts) {
                    return (
                      <div className="mt-3 space-y-2 bg-slate-900/90 p-3.5 rounded-xl border border-slate-800">
                        <div className="text-[11px] font-bold text-sky-400 flex justify-between items-center">
                          <span>
                            {t('Active Plan for', 'ለ')} {profileForStore?.store_name || currentViewSchedule.store_name}:
                          </span>
                          <span className="text-[10px] text-slate-400 font-mono">
                            {currentViewSchedule.installments.filter(i => i.status === 'PAID').length} / {currentViewSchedule.installments.length} {t('Paid', 'ተከፍሏል')}
                          </span>
                        </div>
                        {currentViewSchedule.installments.map(inst => (
                          <div key={inst.installmentNo} className="flex justify-between items-center text-xs py-1.5 border-b border-slate-800/60 font-mono">
                            <div>
                              <span className="text-slate-200">{t('Inst', 'ክፍል')} #{inst.installmentNo} ({t('Due', 'ቀን')}: {inst.dueDate})</span>
                            </div>
                            <div className="flex items-center gap-2">
                              <span className="text-amber-400 font-bold">{inst.amount.toFixed(2)} ETB</span>
                              {inst.status === 'PAID' ? (
                                <span className="px-2 py-0.5 rounded text-[10px] bg-emerald-500/20 text-emerald-400 font-bold">{t('PAID', 'ተከፍሏል')}</span>
                              ) : (
                                <button
                                  onClick={() => {
                                    const targetTx = transactions.find(t => t.customer_id === profileForStore?.id && t.status !== 'SETTLED')
                                      || transactions.find(t => t.merchant_id === profileForStore?.merchant_id && t.status !== 'SETTLED')
                                      || transactions.find(t => t.status !== 'SETTLED')
                                      || transactions[0];
                                    if (targetTx) {
                                      setSelectedTxForPayment({
                                        ...targetTx,
                                        store_name: profileForStore ? profileForStore.store_name : targetTx.store_name,
                                        merchant_id: profileForStore ? profileForStore.merchant_id : targetTx.merchant_id,
                                        customer_id: profileForStore ? profileForStore.id : targetTx.customer_id,
                                        total_amount: inst.amount,
                                        installmentNo: inst.installmentNo
                                      });
                                    }
                                  }}
                                  className="px-2 py-0.5 rounded bg-emerald-600 hover:bg-emerald-500 text-white text-[10px] font-bold cursor-pointer transition-all"
                                >
                                  {t('Pay', 'ክፈል')}
                                </button>
                              )}
                            </div>
                          </div>
                        ))}
                        <button
                          onClick={() => {
                            setSelectedScheduleMerchant(String(profileForStore?.merchant_id || ''));
                            openScheduleModal();
                          }}
                          className="w-full mt-1 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-[10px] font-semibold transition-colors cursor-pointer"
                        >
                          {t('Change / Customize Schedule', 'የጊዜ ሰሌዳውን ቀይር / አስተካክል')}
                        </button>
                      </div>
                    );
                  }

                  // No schedule for this specific selected store yet
                  return (
                    <div className="mt-4 p-4 rounded-xl bg-slate-900/80 border border-slate-800/80 text-center space-y-2.5">
                      <p className="text-xs text-slate-300 font-medium">
                        {t('No repayment schedule set up for', 'ለ')} {profileForStore?.store_name || 'this store'} {t('yet', 'አልተዘጋጀም')} (Debt: {profileForStore?.current_balance?.toFixed(2) || '0.00'} ETB).
                      </p>
                      <button
                        onClick={() => {
                          setSelectedScheduleMerchant(String(profileForStore?.merchant_id || ''));
                          openScheduleModal();
                        }}
                        className="px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold shadow-lg shadow-sky-600/20 inline-flex items-center gap-1.5 cursor-pointer transition-all"
                      >
                        <Clock className="w-4 h-4" />
                        <span>
                          {t('Create Schedule for', 'ለ')} {profileForStore?.store_name || 'Store'}
                        </span>
                      </button>
                    </div>
                  );
                })()}
              </div>

              {/* Itemized Dube Receipts */}
              <div className="glass-panel p-6 rounded-2xl border border-slate-800">
                <div className="mb-4">
                  <h3 className="text-base font-extrabold text-slate-100 flex items-center gap-2">
                    <Receipt className="w-5 h-5 text-emerald-400" />
                    {t('Itemized Pending Dube Receipts', 'ያልተከፈሉ የዱቤ ደረሰኞች ዝርዝር')}
                  </h3>
                  <p className="text-xs text-slate-400">{t('Pay back instantly via Telebirr, Chapa, or CBE Birr', 'በቴሌብር፣ ቻፓ ወይም ሲቢኢ ብር በፍጥነት ይክፈሉ')}</p>
                </div>

                {pendingTransactions.length === 0 ? (
                  <div className="text-center py-8 text-slate-500 text-xs">{t('No pending credit ledger items found.', 'ምንም ያልተከፈለ የዱቤ ቀሪ ሂሳብ አልተገኘም።')}</div>
                ) : (
                  <div className="space-y-3">
                    {pendingTransactions.map(tx => (
                      <div
                        key={tx.id}
                        className="bg-slate-900/70 p-4 rounded-xl border border-slate-800 flex flex-col md:flex-row justify-between items-start md:items-center gap-4"
                      >
                        <div className="space-y-1">
                          <div className="flex items-center gap-2">
                            <span className="font-mono text-xs font-bold text-emerald-400">{tx.transaction_ref}</span>
                            <span className="text-slate-400 text-xs">•</span>
                            <span className="font-semibold text-slate-200 text-xs">{tx.store_name}</span>
                            <span
                              className={`px-2 py-0.5 rounded-full text-[10px] font-extrabold ${
                                tx.status === 'SETTLED'
                                  ? 'bg-emerald-500/20 text-emerald-400'
                                  : tx.status === 'OVERDUE'
                                  ? 'bg-red-500/20 text-red-400'
                                  : 'bg-amber-500/20 text-amber-400'
                              }`}
                            >
                              {tx.status === 'SETTLED' ? t('SETTLED', 'የተከፈለ') : tx.status === 'OVERDUE' ? t('OVERDUE', 'ቀን ያለፈበት') : t('PENDING', 'ያልተከፈለ')}
                            </span>
                          </div>

                          {/* Line items preview */}
                          <div className="text-xs text-slate-400 flex flex-wrap gap-2 pt-1">
                            {tx.items && tx.items.map((item, i) => (
                              <span key={i} className="bg-slate-800 px-2 py-0.5 rounded text-[11px] text-slate-300">
                                {item.quantity}x {item.name} ({item.total ? item.total.toFixed(2) : ''} ETB)
                              </span>
                            ))}
                          </div>
                        </div>

                        <div className="flex items-center gap-4 self-end md:self-auto">
                          <div className="text-right">
                            <p className="text-xs text-slate-500">{t('Due Date:', 'የመክፈያ ቀን፦')} {tx.due_date ? String(tx.due_date).split('T')[0] : 'N/A'}</p>
                            <p className="font-extrabold text-amber-400 text-base">{tx.total_amount.toFixed(2)} ETB</p>
                          </div>

                          {tx.status !== 'SETTLED' && (
                            <button
                              onClick={() => setSelectedTxForPayment(tx)}
                              className="px-4 py-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs font-bold shadow-lg shadow-emerald-600/20 flex items-center gap-1.5 transition-all cursor-pointer"
                            >
                              <CreditCard className="w-3.5 h-3.5" />
                              <span>{t('Pay Debt', 'ዕዳ ክፈል')}</span>
                            </button>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          )}

          {/* TAB 4: SETTLEMENT HISTORY */}
          {activeTab === 'REPAYMENTS' && (
            <div className="glass-panel p-6 rounded-2xl border border-slate-800">
              <h3 className="text-base font-extrabold text-slate-100 mb-3 flex items-center gap-2">
                <CheckCircle2 className="w-5 h-5 text-sky-400" />
                {t('Digital Repayment Settlement History', 'የዲጂታል ክፍያ ማካካሻ ታሪክ')}
              </h3>
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b border-slate-800 text-slate-400 font-mono">
                      <th className="pb-2">{t('Repayment Ref', 'የክፍያ ማጣቀሻ')}</th>
                      <th className="pb-2">{t('Merchant', 'ነጋዴ')}</th>
                      <th className="pb-2">{t('Gateway', 'ክፍያ መተላለፊያ')}</th>
                      <th className="pb-2">{t('Reference Code', 'ማመሳከሪያ ኮድ')}</th>
                      <th className="pb-2">{t('Amount Paid', 'የተከፈለ መጠን')}</th>
                      <th className="pb-2">{t('Status', 'ሁኔታ')}</th>
                      <th className="pb-2">{t('Timestamp', 'ጊዜ ማህተም')}</th>
                      <th className="pb-2 text-right">{t('Receipt', 'ደረሰኝ')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60 text-slate-200">
                    {repayments.map(r => (
                      <tr key={r.id}>
                        <td className="py-2.5 font-mono text-sky-400 font-bold">{r.repayment_ref}</td>
                        <td className="py-2.5">{r.store_name}</td>
                        <td className="py-2.5 font-semibold text-emerald-400">{r.payment_gateway}</td>
                        <td className="py-2.5 font-mono text-yellow-400">{r.reference_code}</td>
                        <td className="py-2.5 font-bold text-slate-100">{r.amount.toFixed(2)} ETB</td>
                        <td className="py-2.5">
                          <span className={`px-2 py-0.5 rounded-full text-[10px] font-extrabold ${
                            r.status === 'COMPLETED'
                              ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'
                              : r.status === 'REJECTED'
                              ? 'bg-red-500/20 text-red-400 border border-red-500/30'
                              : 'bg-amber-500/20 text-amber-400 border border-amber-500/30'
                          }`}>
                            {r.status === 'PENDING' ? t('⏳ PENDING REVIEW', '⏳ ማረጋገጫ በመጠባበቅ ላይ') : r.status === 'REJECTED' ? t('REJECTED', 'ውድቅ የተደረገ') : t('COMPLETED', 'ተጠናቋል')}
                          </span>
                        </td>
                        <td className="py-2.5 text-slate-500 font-mono">{new Date(r.created_at).toLocaleString()}</td>
                        <td className="py-2.5 text-right">
                          <button
                            onClick={() => setSelectedReceipt(r)}
                            className="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-400 text-[10px] font-bold transition-colors flex items-center gap-1 inline-flex cursor-pointer"
                          >
                            <Receipt className="w-3 h-3" />
                            <span>{t('View', 'እይ')}</span>
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Repayment Modal */}
      {selectedTxForPayment && (
        <PaymentModal
          isOpen={!!selectedTxForPayment}
          onClose={() => setSelectedTxForPayment(null)}
          transaction={selectedTxForPayment}
          customerId={selectedTxForPayment.customer_id}
          onPaymentSuccess={() => {
            fetchCustomerDashboard();
          }}
        />
      )}

      {/* Digital Receipt Modal */}
      {selectedReceipt && (
        <ReceiptModal
          isOpen={!!selectedReceipt}
          onClose={() => setSelectedReceipt(null)}
          receipt={selectedReceipt}
        />
      )}


      {/* Salary Installment Schedule Modal */}
      {scheduleModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md overflow-y-auto">
          <div className="glass-panel w-full max-w-lg p-6 rounded-2xl border border-slate-800 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <div>
                <h3 className="text-lg font-extrabold text-slate-100 flex items-center gap-2">
                  <Calendar className="w-5 h-5 text-sky-400" />
                  Repayment Installment Schedule Builder
                </h3>
                <p className="text-xs text-slate-400 mt-0.5">Structure your Dube debt payoff before the repayment deadline</p>
              </div>
              <button
                onClick={() => {
                  setScheduleModalOpen(false);
                  setScheduleResult(null);
                }}
                className="text-slate-500 hover:text-slate-300 p-1.5 rounded-lg hover:bg-slate-800 transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-4">
              {/* Selected Target Account Banner */}
              {(() => {
                const selectedP = selectedScheduleMerchant !== 'ALL'
                  ? profiles.find(pr => String(pr.merchant_id) === String(selectedScheduleMerchant))
                  : null;

                return (
                  <div className="flex items-center justify-between px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-800">
                    <span className="text-xs text-slate-400 font-semibold">{t('Target Account:', 'የተመረጠ አካውንት፦')}</span>
                    <span className="text-xs font-bold text-sky-400 flex items-center gap-1.5 font-mono">
                      {selectedP ? (
                        <>
                          <Store className="w-3.5 h-3.5 text-emerald-400" />
                          <span>{selectedP.store_name}</span>
                        </>
                      ) : (
                        <span>🌐 {t('All Stores Combined', 'የሁሉም ሱቆች በጋራ')}</span>
                      )}
                    </span>
                  </div>
                );
              })()}

              {/* 1. Current Dube Balance */}
              {(() => {
                let displayBalance = summary.totalBalance;
                let storeLabel = `Total across all ${summary.activeAccountsCount || 1} stores`;
                let selectedTx = transactions[0];
                if (selectedScheduleMerchant !== 'ALL') {
                  const selectedP = profiles.find(pr => String(pr.merchant_id) === String(selectedScheduleMerchant));
                  if (selectedP) {
                    displayBalance = selectedP.current_balance;
                    storeLabel = selectedP.store_name;
                    selectedTx = transactions.find(t => t.merchant_id === selectedP.merchant_id) || transactions[0];
                  }
                }
                return (
                  <>
                    <div>
                      <div className="flex justify-between items-center mb-1">
                        <label className="block text-xs font-bold text-slate-300">{t('Current Dube Balance:', 'የአሁኑ የዱቤ ቀሪ ሂሳብ፦')}</label>
                        <span className="text-[10px] text-slate-400 font-sans">({storeLabel})</span>
                      </div>
                      <div className="bg-slate-900 px-3 py-2.5 rounded-xl border border-slate-800 text-sm font-extrabold text-amber-400">
                        {displayBalance.toFixed(2)} ETB
                      </div>
                    </div>

                    {/* Current Dube Repayment Deadline */}
                    <div>
                      <label className="block text-xs font-bold text-slate-300 mb-1">{t('Current Dube Repayment Deadline:', 'የዱቤ መክፈያ የጊዜ ገደብ፦')}</label>
                      <div className="bg-slate-900 px-3 py-2.5 rounded-xl border border-slate-800 text-sm font-extrabold text-emerald-400 font-mono">
                        {selectedTx?.due_date ? String(selectedTx.due_date).split('T')[0] : 'N/A'}
                      </div>
                    </div>
                  </>
                );
              })()}

              {/* 2. Repayment Frequency */}
              <div>
                <label className="block text-xs font-bold text-slate-300 mb-1">Repayment Frequency:</label>
                <select
                  value={frequency}
                  onChange={e => {
                    setFrequency(e.target.value);
                    setScheduleResult(null);
                  }}
                  className="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2.5 text-xs text-slate-100 font-semibold focus:border-sky-500 outline-none"
                >
                  <option value="WEEKLY">Weekly Installments</option>
                  <option value="MONTHLY">Monthly Installments</option>
                </select>
              </div>

              {/* 3. Number of Installments */}
              <div>
                <label className="block text-xs font-bold text-slate-300 mb-1">Number of Installments:</label>
                <select
                  value={numInstallments}
                  onChange={e => {
                    setNumInstallments(parseInt(e.target.value));
                    setScheduleResult(null);
                  }}
                  className="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2.5 text-xs text-slate-100 font-semibold focus:border-sky-500 outline-none"
                >
                  <option value={2}>2 Installments</option>
                  <option value={3}>3 Installments</option>
                  <option value={4}>4 Installments</option>
                  <option value={6}>6 Installments</option>
                  <option value={12}>12 Installments</option>
                </select>
              </div>


              {/* 6. Calculate Schedule Button */}
              <button
                onClick={handleGenerateSchedule}
                className="w-full py-3 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold text-xs shadow-md transition-all cursor-pointer flex items-center justify-center gap-2"
              >
                <Clock className="w-4 h-4" />
                <span>Calculate Schedule</span>
              </button>

              {/* 7. Show Installment Breakdown */}
              {scheduleResult && (
                <div className="space-y-3 pt-2">
                  <div className="bg-slate-900 p-4 rounded-xl border border-slate-800 space-y-2.5 max-h-60 overflow-y-auto">
                    <div className="flex justify-between items-center pb-2 border-b border-slate-800">
                      <h4 className="text-xs font-bold text-emerald-400">Installment Breakdown:</h4>
                      <span className="text-[10px] text-slate-400 font-mono">
                        {scheduleResult.installments.length} Installments
                      </span>
                    </div>
                    {scheduleResult.installments.map(inst => (
                      <div key={inst.installmentNo} className="flex justify-between items-center text-xs py-1.5 px-2 bg-slate-950/60 rounded-lg border border-slate-800/80 font-mono">
                        <div className="flex items-center gap-2">
                          <span className="w-5 h-5 rounded-full bg-sky-500/20 text-sky-400 text-[10px] font-bold flex items-center justify-center">
                            #{inst.installmentNo}
                          </span>
                          <span className="text-slate-200">Due Date: {inst.dueDate}</span>
                        </div>
                        <span className="text-amber-400 font-bold">{inst.amount.toFixed(2)} ETB</span>
                      </div>
                    ))}
                  </div>

                  {/* 8. Customer Confirm */}
                  <button
                    onClick={handleApplySchedule}
                    disabled={applyingSchedule}
                    className="w-full py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-extrabold text-xs shadow-lg shadow-emerald-600/20 flex items-center justify-center gap-2 cursor-pointer transition-all disabled:opacity-50"
                  >
                    <CheckCircle2 className="w-4 h-4" />
                    <span>{applyingSchedule ? 'Confirming...' : 'Customer Confirm & Apply Schedule'}</span>
                  </button>
                </div>
              )}

              <button
                onClick={() => {
                  setScheduleModalOpen(false);
                  setScheduleResult(null);
                }}
                className="w-full py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold transition-colors"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Alerts Warnings Modal */}
      {alertsOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md">
          <div className="glass-panel w-full max-w-lg rounded-2xl border border-slate-800 p-6 shadow-2xl space-y-4">
            {/* Header */}
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <h3 className="text-base font-extrabold text-slate-100 flex items-center gap-2">
                <Bell className="w-5 h-5 text-amber-400" />
                Merchant Warning Alerts & Notices ({visibleNotifications.length})
              </h3>
              <button
                onClick={() => setAlertsOpen(false)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* List */}
            <div className="space-y-3 max-h-[350px] overflow-y-auto pr-1">
              {visibleNotifications.length === 0 ? (
                <div className="text-center py-8 text-slate-500 text-xs bg-slate-900/30 rounded-xl border border-slate-900 p-4">
                  📭 You have no warning alerts or SMS notifications from your merchants yet.
                </div>
              ) : (
                visibleNotifications.map(n => {
                  const isOverdue = n.type === 'OVERDUE_ALERT';
                  const isReminder = n.type === 'REMINDER';
                  return (
                    <div
                      key={n.id}
                      className={`p-3.5 rounded-xl border flex gap-3 items-start transition-all ${
                        isOverdue
                          ? 'bg-red-500/10 border-red-500/20 text-red-200'
                          : isReminder
                          ? 'bg-amber-500/10 border-amber-500/20 text-amber-200'
                          : 'bg-slate-900/80 border-slate-800 text-slate-300'
                      }`}
                    >
                      <div className="mt-0.5 shrink-0">
                        {isOverdue ? (
                          <AlertCircle className="w-4 h-4 text-red-400 animate-pulse" />
                        ) : (
                          <Bell className="w-4 h-4 text-amber-400" />
                        )}
                      </div>
                      <div className="flex-1 space-y-1">
                        <div className="flex justify-between items-center text-[10px] uppercase font-mono tracking-wider font-extrabold">
                          <span className={isOverdue ? 'text-red-400' : isReminder ? 'text-amber-400' : 'text-slate-400'}>
                            {n.type === 'OVERDUE_ALERT' && '⚠️ Critical Overdue Warning'}
                            {n.type === 'REMINDER' && '📅 Repayment Reminder'}
                            {n.type === 'CREDIT_ISSUED' && '💳 New Credit Logged'}
                            {n.type === 'PAYMENT_RECEIPT' && '🧾 Payment Receipt Confirmed'}
                            {!['OVERDUE_ALERT', 'REMINDER', 'CREDIT_ISSUED', 'PAYMENT_RECEIPT'].includes(n.type) && '💬 Store Alert'}
                          </span>
                          <span className="text-slate-500 font-normal normal-case">
                            {new Date(n.sent_at).toLocaleString()}
                          </span>
                        </div>
                        <p className="text-xs leading-relaxed font-sans">{n.message}</p>
                        <div className="flex justify-between items-center pt-1 border-t border-slate-800/40 text-[9px] font-mono text-slate-500">
                          <span>Phone: {n.phone}</span>
                          <div className="flex items-center gap-2">
                            <span className={`px-1.5 py-0.5 rounded font-bold uppercase ${
                              n.status === 'DELIVERED' || n.status === 'Success'
                                ? 'bg-emerald-500/10 text-emerald-400'
                                : 'bg-slate-800 text-slate-400'
                            }`}>
                              Status: {n.status}
                            </span>
                            <button
                              onClick={() => dismissAlert(n.id)}
                              title="Remove notification"
                              className="ml-1 text-slate-600 hover:text-red-400 transition-colors"
                            >
                              <X className="w-3.5 h-3.5" />
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                })
              )}
            </div>

            <button
              onClick={() => setAlertsOpen(false)}
              className="w-full py-2.5 rounded-xl bg-slate-850 hover:bg-slate-800 text-slate-200 text-xs font-bold transition-all cursor-pointer"
            >
              Close Alerts Feed
            </button>
          </div>
        </div>
      )}
    </div>
  );
};
